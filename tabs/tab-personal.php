<?php
defined('ABSPATH') || exit;

// tab-personal.php


$student_id = intval($_GET['student_id'] ?? 0);
if (!$student_id) {
    echo '<p>No student selected.</p>';
    return;
}

$edit_mode = isset($_GET['edit']) && $_GET['edit'] == '1';

// Handle form submission
if ($edit_mode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['personal_nonce']) && wp_verify_nonce($_POST['personal_nonce'], 'edit_personal')) {
    $fields = [
        'nome', 'sobrenome', 'data_de_nascimento', 'estado_civil', 'phone', 'Street', 'bairro', 'cidade', 'estado', 'cep', 'pais',
        'church_name', 'Pastor_name', 'Pastor_status', 'church_description', 'Theology_stance', 'current_ministry', 'como_estudar', 'validacao', 'motivo', 'salvacao'
    ];
    foreach ($fields as $meta_key) {
        if (isset($_POST[$meta_key])) {
            update_user_meta($student_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
        }
    }
    echo '<div class="updated"><p>Dados pessoais atualizados com sucesso.</p></div>';
}

function sdd_meta($user_id, $key) {
    return get_user_meta($user_id, $key, true) ?: '';
}

echo '<h3>Dados Pessoais</h3>';
$toggle_url = esc_url(add_query_arg(['student_id' => $student_id, 'tab' => 'personal', 'edit' => $edit_mode ? '0' : '1']));
echo '<p><a class="button" href="' . $toggle_url . '">' . ($edit_mode ? 'Visualizar' : 'Editar') . '</a></p>';

if ($edit_mode) {
    echo '<form method="post">';
    wp_nonce_field('edit_personal', 'personal_nonce');
    echo '<table class="form-table">';
    echo '<tr><th>Nome</th><td><input type="text" name="nome" value="' . esc_attr(sdd_meta($student_id, 'nome')) . '" /></td></tr>';
    echo '<tr><th>Sobrenome</th><td><input type="text" name="sobrenome" value="' . esc_attr(sdd_meta($student_id, 'sobrenome')) . '" /></td></tr>';
    echo '<tr><th>Data de Nascimento</th><td><input type="text" name="data_de_nascimento" value="' . esc_attr(sdd_meta($student_id, 'data_de_nascimento')) . '" /></td></tr>';
    echo '<tr><th>Estado Civil</th><td><input type="text" name="estado_civil" value="' . esc_attr(sdd_meta($student_id, 'estado_civil')) . '" /></td></tr>';
    echo '<tr><th>Telefone</th><td><input type="text" name="phone" value="' . esc_attr(sdd_meta($student_id, 'phone')) . '" /></td></tr>';
    echo '<tr><th>Rua e número</th><td><input type="text" name="Street" value="' . esc_attr(sdd_meta($student_id, 'Street')) . '" /></td></tr>';
    echo '<tr><th>Bairro</th><td><input type="text" name="bairro" value="' . esc_attr(sdd_meta($student_id, 'bairro')) . '" /></td></tr>';
    echo '<tr><th>Cidade</th><td><input type="text" name="cidade" value="' . esc_attr(sdd_meta($student_id, 'cidade')) . '" /></td></tr>';
    echo '<tr><th>Estado</th><td><input type="text" name="estado" value="' . esc_attr(sdd_meta($student_id, 'estado')) . '" /></td></tr>';
    echo '<tr><th>CEP</th><td><input type="text" name="cep" value="' . esc_attr(sdd_meta($student_id, 'cep')) . '" /></td></tr>';
    echo '<tr><th>País</th><td><input type="text" name="pais" value="' . esc_attr(sdd_meta($student_id, 'pais')) . '" /></td></tr>';
    echo '<tr><th>Igreja</th><td><input type="text" name="church_name" value="' . esc_attr(sdd_meta($student_id, 'church_name')) . '" /></td></tr>';
    echo '<tr><th>Pastor</th><td><input type="text" name="Pastor_name" value="' . esc_attr(sdd_meta($student_id, 'Pastor_name')) . '" /></td></tr>';
    echo '<tr><th>É o Pastor?</th><td><input type="text" name="Pastor_status" value="' . esc_attr(sdd_meta($student_id, 'Pastor_status')) . '" /></td></tr>';
    echo '<tr><th>Descrição da Igreja</th><td><input type="text" name="church_description" value="' . esc_attr(sdd_meta($student_id, 'church_description')) . '" /></td></tr>';
    echo '<tr><th>Teologia</th><td><input type="text" name="Theology_stance" value="' . esc_attr(sdd_meta($student_id, 'Theology_stance')) . '" /></td></tr>';
    echo '<tr><th>Ministério Atual</th><td><input type="text" name="current_ministry" value="' . esc_attr(sdd_meta($student_id, 'current_ministry')) . '" /></td></tr>';
    echo '<tr><th>Como pretende estudar</th><td><input type="text" name="como_estudar" value="' . esc_attr(sdd_meta($student_id, 'como_estudar')) . '" /></td></tr>';
    echo '<tr><th>Co-validação?</th><td><input type="text" name="validacao" value="' . esc_attr(sdd_meta($student_id, 'validacao')) . '" /></td></tr>';
    echo '<tr><th>Motivo da matrícula</th><td><input type="text" name="motivo" value="' . esc_attr(sdd_meta($student_id, 'motivo')) . '" /></td></tr>';
    echo '<tr><th>Testemunho</th><td><textarea name="salvacao" rows="4">' . esc_textarea(sdd_meta($student_id, 'salvacao')) . '</textarea></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="Salvar"></p>';
    echo '</form>';
} else {
    echo '<table class="widefat fixed striped"><thead><tr><th>Campo</th><th>Valor</th></tr></thead><tbody>';
    echo '<tr><td>Nome</td><td>' . esc_html(sdd_meta($student_id, 'nome')) . ' ' . esc_html(sdd_meta($student_id, 'sobrenome')) . '</td></tr>';
    echo '<tr><td>Data de Nascimento</td><td>' . esc_html(sdd_meta($student_id, 'data_de_nascimento')) . '</td></tr>';
    echo '<tr><td>Estado Civil</td><td>' . esc_html(sdd_meta($student_id, 'estado_civil')) . '</td></tr>';
    echo '<tr><td>Telefone</td><td>' . esc_html(sdd_meta($student_id, 'phone')) . '</td></tr>';
    echo '<tr><td>Rua e número</td><td>' . esc_html(sdd_meta($student_id, 'Street')) . '</td></tr>';
    echo '<tr><td>Bairro</td><td>' . esc_html(sdd_meta($student_id, 'bairro')) . '</td></tr>';
    echo '<tr><td>Cidade</td><td>' . esc_html(sdd_meta($student_id, 'cidade')) . '</td></tr>';
    echo '<tr><td>Estado</td><td>' . esc_html(sdd_meta($student_id, 'estado')) . '</td></tr>';
    echo '<tr><td>CEP</td><td>' . esc_html(sdd_meta($student_id, 'cep')) . '</td></tr>';
    echo '<tr><td>País</td><td>' . esc_html(sdd_meta($student_id, 'pais')) . '</td></tr>';
    echo '<tr><td>Igreja</td><td>' . esc_html(sdd_meta($student_id, 'church_name')) . '</td></tr>';
    echo '<tr><td>Pastor</td><td>' . esc_html(sdd_meta($student_id, 'Pastor_name')) . '</td></tr>';
    echo '<tr><td>É o Pastor?</td><td>' . esc_html(sdd_meta($student_id, 'Pastor_status')) . '</td></tr>';
    echo '<tr><td>Descrição da Igreja</td><td>' . esc_html(sdd_meta($student_id, 'church_description')) . '</td></tr>';
    echo '<tr><td>Teologia</td><td>' . esc_html(sdd_meta($student_id, 'Theology_stance')) . '</td></tr>';
    echo '<tr><td>Ministério Atual</td><td>' . esc_html(sdd_meta($student_id, 'current_ministry')) . '</td></tr>';
    echo '<tr><td>Como pretende estudar</td><td>' . esc_html(sdd_meta($student_id, 'como_estudar')) . '</td></tr>';
    echo '<tr><td>Co-validação?</td><td>' . esc_html(sdd_meta($student_id, 'validacao')) . '</td></tr>';
    echo '<tr><td>Motivo da matrícula</td><td>' . esc_html(sdd_meta($student_id, 'motivo')) . '</td></tr>';
    echo '<tr><td>Testemunho</td><td>' . nl2br(esc_html(sdd_meta($student_id, 'salvacao'))) . '</td></tr>';
    echo '</tbody></table>';
}
