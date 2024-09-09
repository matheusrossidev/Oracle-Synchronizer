<?php
/**
 * Plugin Name: Sincronizador Oracle Brago
 * Plugin URI: https://github.com/matheusrossidev
 * Description: Sincroniza produtos com um banco de dados Oracle.
 * Version: 1.0
 * Author: Matheus Rossi
 * Author URI: https://github.com/matheusrossidev
 */

if (!defined('ABSPATH')) exit;

class SincronizadorOracleBrago {
    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'schedule_sync'));
        add_action('sincronizar_produtos', array($this, 'sincronizar_produtos'));
    }

    public function register_post_type() {
        $args = array(
            'public' => true,
            'label'  => 'Produtos',
            'supports' => array('title', 'custom-fields'),
        );
        register_post_type('produto', $args);

        // Registrar campos personalizados
        add_action('add_meta_boxes', array($this, 'add_custom_fields'));
        add_action('save_post', array($this, 'save_custom_fields'));
    }

    public function add_custom_fields() {
        add_meta_box('produto_fields', 'Detalhes do Produto', array($this, 'produto_fields_callback'), 'produto');
    }

    public function produto_fields_callback($post) {
        $codigo = get_post_meta($post->ID, 'codigo_produto', true);
        $unidade = get_post_meta($post->ID, 'unidade', true);
        $foto = get_post_meta($post->ID, 'foto_produto', true);

        echo '<label for="codigo_produto">Código do Produto:</label>';
        echo '<input type="text" id="codigo_produto" name="codigo_produto" value="' . esc_attr($codigo) . '"><br>';

        echo '<label for="unidade">Unidade:</label>';
        echo '<input type="text" id="unidade" name="unidade" value="' . esc_attr($unidade) . '"><br>';

        echo '<label for="foto_produto">Foto do Produto:</label>';
        echo '<input type="text" id="foto_produto" name="foto_produto" value="' . esc_attr($foto) . '">';
    }

    public function save_custom_fields($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        $fields = array('codigo_produto', 'unidade', 'foto_produto');

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }

    public function add_admin_menu() {
        add_menu_page('Sincronizador Oracle', 'Sincronizador Oracle', 'manage_options', 'sincronizador-oracle', array($this, 'admin_page'));
    }

    public function admin_page() {
        echo '<div class="wrap">';
        echo '<h1>Sincronizador Oracle Brago</h1>';
        echo '<p>Clique no botão abaixo para sincronizar manualmente os produtos.</p>';
        echo '<form method="post">';
        echo '<input type="submit" name="sincronizar" class="button button-primary" value="Sincronizar Agora">';
        echo '</form>';
        echo '</div>';

        if (isset($_POST['sincronizar'])) {
            $this->sincronizar_produtos();
            echo '<div class="updated"><p>Sincronização concluída!</p></div>';
        }
    }

    public function schedule_sync() {
        if (!wp_next_scheduled('sincronizar_produtos')) {
            wp_schedule_event(time(), 'daily', 'sincronizar_produtos');
        }
    }

    public function sincronizar_produtos() {
        // Conexão com o banco de dados Oracle
    $conn = oci_connect('USER', 'PASSWORD', 'IP:PORT/SERVICE');

        if (!$conn) {
            $e = oci_error();
            trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
        }

        $sql = "SELECT CODPROD, DESCRICAO, UNIDADE, DIRFOTOPROD FROM pcprodut WHERE dtexclusao IS NULL AND obs <> 'PV'";
        $stid = oci_parse($conn, $sql);
        oci_execute($stid);

        $produtos_atualizados = array();

        while ($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS)) {
            $post_id = $this->update_or_create_product($row);
            $produtos_atualizados[] = $post_id;
        }

        // Remover produtos que não estão mais na consulta
        $args = array(
            'post_type' => 'produto',
            'posts_per_page' => -1,
            'post__not_in' => $produtos_atualizados,
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                wp_delete_post(get_the_ID(), true);
            }
        }

        wp_reset_postdata();

        oci_free_statement($stid);
        oci_close($conn);
    }

    private function update_or_create_product($row) {
        $args = array(
            'post_type' => 'produto',
            'meta_query' => array(
                array(
                    'key' => 'codigo_produto',
                    'value' => $row['CODPROD'],
                    'compare' => '='
                )
            )
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
        } else {
            $post_id = wp_insert_post(array(
                'post_title' => $row['DESCRICAO'],
                'post_type' => 'produto',
                'post_status' => 'publish'
            ));
        }

        update_post_meta($post_id, 'codigo_produto', $row['CODPROD']);
        update_post_meta($post_id, 'unidade', $row['UNIDADE']);
        update_post_meta($post_id, 'foto_produto', $row['DIRFOTOPROD']);

        return $post_id;
    }
}

new SincronizadorOracleBrago();