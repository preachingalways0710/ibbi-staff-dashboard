<?php
defined('ABSPATH') || exit;

// tab-status.php

// Get student ID
$student_id = intval($_GET['student_id'] ?? 0);
if (!$student_id) {
    echo '<p>No student selected.</p>';
    return;
}

// Check if edit mode is enabled
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == '1';

// Handle form submission
if ($edit_mode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sdd_status_nonce']) && wp_verify_nonce($_POST['sdd_status_nonce'], 'sdd_status_edit')) {
    $fields = [
        'sdd_academic_status', 'es', 'parcelas_pagas', 'sdd_cov_status', 'sdd_student_notes'
    ];
    foreach ($fields as $meta_key) {
        if (isset($_POST[$meta_key])) {
            update_user_meta($student_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
        }
    }
    echo '<div class="updated"><p>Status atualizado com sucesso.</p></div>';
}

// Helper to safely retrieve user meta
function sdd_meta_status($user_id, $key) {
    $value = get_user_meta($user_id, $key, true);
    if (is_array($value)) return implode(', ', $value);
    return $value ?: '';
}

// Fields to display

// Get latest TutorLMS enrollment date using direct SQL for reliability
global $wpdb;
$enrollment_date = '';
$enrol_date = $wpdb->get_var($wpdb->prepare(
    "SELECT post_date FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_author = %d ORDER BY post_date DESC LIMIT 1",
    $student_id
));
if ($enrol_date) {
    $enrollment_date = date_i18n('F j, Y @ g:i a', strtotime($enrol_date));
}

$fields = [
    'Data de Matrícula' => 'enrollment_date',
    'Status' => 'sdd_academic_status',
    'Pagamento (Escolha)' => 'es',
    'Parcelas Pagas' => 'parcelas_pagas',
    'Co-Validação' => 'sdd_cov_status',
    'Últimas Notícias' => 'sdd_student_notes'
];

echo '<h3>Status Acadêmico</h3>';

// Editable Academic Status Fields (moved from progress tab)
$status_aluno = get_user_meta($student_id, 'status_aluno', true);
$pagamento = get_user_meta($student_id, 'pagamento', true);
$co_validacao = get_user_meta($student_id, 'co_validacao', true);
$anotacoes = get_user_meta($student_id, 'anotacoes_personalizadas', true);

if ($edit_mode) {
    echo '<form method="post">';
    wp_nonce_field('save_academic_status', 'academic_status_nonce');
    echo '<table class="form-table">';
    echo '<tr><th><label for="status_aluno">Status do Aluno</label></th><td><input type="text" name="status_aluno" id="status_aluno" class="regular-text" value="' . esc_attr($status_aluno) . '" readonly></td></tr>';
    echo '<tr><th><label for="pagamento">Progresso de Pagamento</label></th><td><input type="text" name="pagamento" id="pagamento" class="regular-text" value="' . esc_attr($pagamento) . '"></td></tr>';
    echo '<tr><th><label for="co_validacao">Co-validação</label></th><td><input type="text" name="co_validacao" id="co_validacao" class="regular-text" value="' . esc_attr($co_validacao) . '"></td></tr>';
    echo '<tr><th><label for="anotacoes_personalizadas">Anotações</label></th><td><textarea name="anotacoes_personalizadas" id="anotacoes_personalizadas" rows="5" class="large-text">' . esc_textarea($anotacoes) . '</textarea></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="Salvar Dados"></p>';
    echo '</form>';
}

// Toggle button
$toggle_url = esc_url(add_query_arg(['student_id' => $student_id, 'edit' => $edit_mode ? '0' : '1']));
echo '<p><a class="button" href="' . $toggle_url . '">' . ($edit_mode ? 'Visualizar' : 'Editar') . '</a></p>';

if ($edit_mode) {
    echo '<form method="post">';
    wp_nonce_field('sdd_status_edit', 'sdd_status_nonce');
    echo '<table class="widefat fixed striped"><thead><tr><th>Campo</th><th>Valor</th></tr></thead><tbody>';
    foreach ($fields as $label => $meta_key) {
        if ($meta_key === 'enrollment_date') {
            $input = $enrollment_date ? esc_html($enrollment_date) : '<em>Não definido</em>';
            echo "<tr><td>{$label}</td><td>{$input}</td></tr>";
            continue;
        }
        $value = sdd_meta_status($student_id, $meta_key);
        $input = ($meta_key === 'sdd_student_notes')
            ? '<textarea name="' . esc_attr($meta_key) . '" rows="3" style="width:100%">' . esc_textarea($value) . '</textarea>'
            : '<input type="text" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '" style="width:100%" />';
        echo "<tr><td>{$label}</td><td>{$input}</td></tr>";
    }
    echo '</tbody></table>';
    echo '<p><input type="submit" class="button-primary" value="Salvar" /></p>';
    echo '</form>';
} else {
    echo '<table class="widefat fixed striped"><thead><tr><th>Campo</th><th>Valor</th></tr></thead><tbody>';
    foreach ($fields as $label => $meta_key) {
        if ($meta_key === 'enrollment_date') {
            $value = $enrollment_date ? esc_html($enrollment_date) : '<em>Não definido</em>';
            echo "<tr><td>{$label}</td><td>{$value}</td></tr>";
            continue;
        }
        $value = sdd_meta_status($student_id, $meta_key);
        if ($meta_key === 'sdd_student_notes') {
            $value = nl2br(esc_html($value));
        } else {
            $value = esc_html($value ?: '<em>Não definido</em>');
        }
        echo "<tr><td>{$label}</td><td>{$value}</td></tr>";
    }
    echo '</tbody></table>';
}
