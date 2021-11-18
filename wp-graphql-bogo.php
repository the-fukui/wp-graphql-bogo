<?php
/*
Plugin Name: WP GraphQL BOGO
Plugin URI: null
Description: WP GraphQL BOGO
Author: the-fukui
Version: 0.2
*/
use WPGraphQL\Registry\TypeRegistry;
use WPGraphQL\Connection\PostObjects;
use WPGraphQL\Model\Post;
use WPGraphQL\Data\Connection\PostObjectConnectionResolver;

if (!defined('ABSPATH')) {
    exit();
}

add_action('graphql_init', 'graphql_init_bogo_query');

function graphql_init_bogo_query()
{
    new WPGraphQL_Bogo();
}

class WPGraphQL_Bogo
{
    private array $localizable_post_types;
    private array $taxonomies;


    public function __construct()
    {
        $this->get_localizable_post_types();
        add_action('cptui_init', array($this,'get_taxonomies'));

        //Bogo対応投稿タイプにlocale, originalIDフィールドを追加
        //Bogoのデフォルトロケールとロケール一覧を追加
        add_action('graphql_register_types', [$this, 'register_fields'], 9, 0);

        //Bogo対応投稿タイプにオリジナル（派生元）投稿へのconnectionを追加
        add_action('graphql_register_types', [$this, 'register_connections']);

        //locales type(enum)を追加
        add_action('graphql_register_types', [$this, 'register_types'], 9, 0);

        //whereArgsにlocale conditionを追加
        add_filter('graphql_input_fields', [$this, 'register_input_fields'], 10, 4);

        //クエリを反映
        add_filter('graphql_map_input_fields_to_wp_query', [$this, 'map_input_fields_to_query'], 10, 2);
    }

    public function array_some($array, $callable)
    {
        $count = count($array);
        $keys = array_keys($array);

        for ($i = 0; $i < $count; $i += 1) {
            if ($callable($array[$keys[$i]])) {
                return true;
            }
        }

        return false;
    }

    private function get_localizable_post_types()
    {
        $this->localizable_post_types = bogo_localizable_post_types();
    }

    public function get_taxonomies()
    {
        $this->taxonomies = get_taxonomies(array(), 'objects');
    }

    public function register_input_fields(
        array $fields,
        string $type_name,
        array $config,
        TypeRegistry $type_registry
    ) {
        //bogoが有効なクエリタイプかどうか

        //投稿タイプ
        $is_locale_available = $this->array_some($this->localizable_post_types, function ($post_type) use ($config) {
            $graphql_post_type_name = get_post_type_object($post_type)->graphql_single_name;
            $available_type_name_preg = '/^.*To' . ucfirst($graphql_post_type_name) . 'ConnectionWhereArgs$/';
            return preg_match($available_type_name_preg, $config['name']);
        });

        //content node
        $is_content_node = $type_name === 'RootQueryToContentNodeConnectionWhereArgs';
        $is_hierarchical_content_node =
            $type_name === 'HierarchicalContentNodeToContentNodeChildrenConnectionWhereArgs';

        // //taxonomy
        // $is_taxonomy = $this->array_some($this->taxonomies, function ($taxonomy) use ($config) {
        //     $taxonomy_name_preg = '/^.*To' . ucfirst($taxonomy->graphql_single_name) . 'ConnectionWhereArgs$/';
        //     return preg_match($taxonomy_name_preg, $config['name']);
        // });

        if ($is_locale_available || $is_content_node || $is_hierarchical_content_node) {
            $fields['locale'] = [
                'type' => 'ConnectionWhereArgsBogoLocales',
                'description' => __('Locale of the post', 'wp-graphql'),
            ];
        }

        return $fields;
    }

    public function register_fields()
    {
        //Bogoが利用可能な投稿タイプごとにフィールド登録
        foreach (bogo_localizable_post_types() as $post_type) {
            $graphql_post_type_name = get_post_type_object($post_type)->graphql_single_name;
            //投稿のlocaleを表示
            register_graphql_field($graphql_post_type_name, 'locale', [
                'type' => 'String',
                'description' => __('Locale of the post', 'wp-graphql'),
                'resolve' => function ($post) {
                    $locale = get_post_meta($post->ID, '_locale', true);
                    return !empty($locale) ? $locale : '';
                },
            ]);

            //オリジナルID（派生元ID）を表示
            register_graphql_field($graphql_post_type_name, 'originalId', [
                'type' => 'String',
                'description' => __('Original ID of the post', 'wp-graphql'),
                'resolve' => function ($post) {
                    $originalId = get_post_meta($post->ID, '_original_post', true);
                    return !empty($originalId) ? $originalId : '';
                },
            ]);
        }

        //デフォルトのロケールを表示
        register_graphql_field('RootQuery', 'defaultLocale', [
            'type' => 'String',
            'description' => __('default locale of the system', 'wp-graphql'),
            'resolve' => function () {
                $default_locale = bogo_get_default_locale();
                return !empty($default_locale) ? $default_locale : '';
            },
        ]);

        //Bogoで利用可能なロケール一覧の表示
        register_graphql_field('RootQuery', 'allLocales', [
            'type' => ['list_of' => 'String'],
            'description' => __('A list of all available locales of Bogo', 'wp-graphql'),
            'resolve' => function () {
                $locales = array_keys(bogo_available_languages());
                return $locales;
            },
        ]);

        //言語別カテゴリカウントを表示
        foreach ($this->taxonomies as $taxonomy) {
            register_graphql_field(ucfirst($taxonomy->graphql_single_name), 'countByLocale', [
            'type' => 'Int',
            'args' => [
                'locale' => [
                    'type' => "ConnectionWhereArgsBogoLocales"
                ]
            ],
            'description' => __('Show post count by locale', 'wp-graphql'),
            'resolve' => function ($term, $args, $context, $info) {
                $query_args = [
                    'post_status' => 'publish',
                    'tax_query' => [
                        [
                            'taxonomy' => $term->taxonomyName,
                            'field'    => 'term_id',
                            'terms'    => $term->term_id,
                        ],
                    ],
                ];

                if (!empty($args['locale'])) {
                    if ($args['locale'] === 'all') {
                        $query_args['bogo_suppress_locale_query'] = true;
                    } else {
                        $query_args['lang'] = $args['locale'];
                        $query_args['bogo_suppress_locale_query'] = false;
                    }
                }

                $q = new WP_Query($query_args);
                $count = $q->found_posts;

                return $count;
            },
            ]);
        }
    }

    public function register_connections()
    {
        //Bogoが利用可能な投稿タイプごとにコネクション登録
        foreach (bogo_localizable_post_types() as $post_type) {
            $graphql_post_type_name = get_post_type_object($post_type)->graphql_single_name;
            register_graphql_connection([
                'fromType' => $graphql_post_type_name,
                'toType' => $graphql_post_type_name,
                'fromFieldName' => 'translations',
                'connectionArgs' => PostObjects::get_connection_args(),
                'resolve' => function (Post $source, $args, $context, $info) use ($post_type) {
                    $original_guid = get_post_meta($source->ID, '_original_post', true);

                    $resolver = new PostObjectConnectionResolver($source, $args, $context, $info, $post_type);
                    $resolver->set_query_arg('meta_key', '_original_post');
                    $resolver->set_query_arg('meta_value', $original_guid);

                    return $resolver->get_connection();
                },
            ]);
        }
    }

    public function register_types()
    {
        $locales = ['all' => ['value' => 'all']];

        foreach (bogo_available_languages() as $key => $value) {
            $locales[$key] = ['value' => $key];
        }

        register_graphql_enum_type('ConnectionWhereArgsBogoLocales', [
            'description' => __('List of available locales', 'wp-graphql'),
            'values' => $locales,
        ]);
    }

    public function map_input_fields_to_query($query_args, $input_args)
    {
        if (!empty($input_args['locale'])) {
            if ($input_args['locale'] === 'all') {
                $query_args['bogo_suppress_locale_query'] = true;
            } else {
                $query_args['lang'] = $input_args['locale'];
                $query_args['bogo_suppress_locale_query'] = false;
            }
        }

        return $query_args;
    }
}
