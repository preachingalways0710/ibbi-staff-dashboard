<?php
/**
 * Plugin Name: IBBI Staff Dashboard
 * Description: Staff-facing Bible Institute dashboard for Tutor LMS student progress and academic follow-up.
 * Version: 1.0.0
 * Author: Mike Schmidt / OpenAI
 */

defined('ABSPATH') || exit;

define('SDD_VERSION', '1.0.0');
define('SDD_PLUGIN_FILE', __FILE__);
define('SDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SDD_PLUGIN_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, 'sdd_activate');
function sdd_activate() {
    if (!wp_next_scheduled('sdd_check_student_inactivity')) {
        wp_schedule_event(time(), 'daily', 'sdd_check_student_inactivity');
    }
}

register_deactivation_hook(__FILE__, 'sdd_deactivate');
function sdd_deactivate() {
    wp_clear_scheduled_hook('sdd_check_student_inactivity');
}

add_action('init', 'sdd_register_shortcodes');
function sdd_register_shortcodes() {
    add_shortcode('ibbi_staff_dashboard', 'sdd_render_staff_dashboard_shortcode');
    add_shortcode('ibbi_dashboard', 'sdd_render_staff_dashboard_shortcode');
}

add_action('wp_enqueue_scripts', 'sdd_register_assets');
function sdd_register_assets() {
    wp_register_style(
        'sdd-dashboard',
        SDD_PLUGIN_URL . 'assets/css/dashboard.css',
        [],
        SDD_VERSION
    );

    wp_register_script(
        'sdd-dashboard',
        SDD_PLUGIN_URL . 'assets/js/dashboard.js',
        [],
        SDD_VERSION,
        true
    );
}

add_action('wp_login', 'sdd_track_last_login', 10, 2);
function sdd_track_last_login($user_login, $user) {
    update_user_meta($user->ID, 'last_login', time());
}

add_action('user_register', 'sdd_set_default_student_status');
function sdd_set_default_student_status($user_id) {
    if (!get_user_meta($user_id, 'status_aluno', true)) {
        update_user_meta($user_id, 'status_aluno', 'Ativo');
    }
}

add_action('sdd_check_student_inactivity', 'sdd_check_student_inactivity');
function sdd_check_student_inactivity() {
    $students = sdd_get_student_users();

    foreach ($students as $student) {
        $last_activity = sdd_get_student_last_activity($student->ID);

        if (!$last_activity) {
            continue;
        }

        $status = (time() - $last_activity) > (14 * DAY_IN_SECONDS) ? 'Parado' : 'Ativo';
        update_user_meta($student->ID, 'status_aluno', $status);
    }
}

add_action('wp_ajax_sdd_dashboard_data', 'sdd_ajax_dashboard_data');
function sdd_ajax_dashboard_data() {
    check_ajax_referer('sdd_dashboard', 'nonce');

    if (!sdd_current_user_can_view_dashboard()) {
        wp_send_json_error(['message' => __('Você não tem permissão para ver este painel.', 'sdd')], 403);
    }

    $view = isset($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : 'overview';
    $filters = sdd_sanitize_dashboard_filters($_POST);

    if ('person' === $view) {
        wp_send_json_success(sdd_get_person_view_data($filters));
    }

    if ('course' === $view) {
        wp_send_json_success(sdd_get_course_view_data($filters));
    }

    wp_send_json_success(sdd_get_overview_data($filters));
}

function sdd_render_staff_dashboard_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'require_login' => 'yes',
            'title' => 'Painel Acadêmico IBBI',
        ],
        $atts,
        'ibbi_staff_dashboard'
    );

    if ('yes' === $atts['require_login'] && !is_user_logged_in()) {
        return '<div class="sdd-dashboard-message">' . esc_html__('Faça login para acessar o painel acadêmico.', 'sdd') . '</div>';
    }

    if (!sdd_current_user_can_view_dashboard()) {
        return '<div class="sdd-dashboard-message">' . esc_html__('Você não tem permissão para acessar este painel.', 'sdd') . '</div>';
    }

    wp_enqueue_style('sdd-dashboard');
    wp_enqueue_script('sdd-dashboard');
    wp_localize_script(
        'sdd-dashboard',
        'sddDashboard',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sdd_dashboard'),
            'labels' => [
                'loading' => __('Carregando dados...', 'sdd'),
                'error' => __('Não foi possível carregar os dados.', 'sdd'),
                'empty' => __('Nenhum resultado encontrado.', 'sdd'),
            ],
        ]
    );

    ob_start();
    ?>
    <section class="sdd-dashboard" data-sdd-dashboard>
        <header class="sdd-dashboard__header">
            <div>
                <p class="sdd-dashboard__eyebrow"><?php echo esc_html__('Bible Institute', 'sdd'); ?></p>
                <h2><?php echo esc_html($atts['title']); ?></h2>
            </div>
            <div class="sdd-dashboard__status">
                <?php echo function_exists('tutor_utils') ? esc_html__('Tutor LMS conectado', 'sdd') : esc_html__('Tutor LMS não detectado', 'sdd'); ?>
            </div>
        </header>

        <nav class="sdd-tabs" aria-label="<?php echo esc_attr__('Visões do painel', 'sdd'); ?>">
            <button class="sdd-tab is-active" type="button" data-sdd-view="overview"><?php echo esc_html__('Overview', 'sdd'); ?></button>
            <button class="sdd-tab" type="button" data-sdd-view="person"><?php echo esc_html__('Por Pessoa', 'sdd'); ?></button>
            <button class="sdd-tab" type="button" data-sdd-view="course"><?php echo esc_html__('Por Matéria', 'sdd'); ?></button>
        </nav>

        <form class="sdd-filters" data-sdd-filters>
            <label>
                <span><?php echo esc_html__('Pesquisar', 'sdd'); ?></span>
                <input type="search" name="search" placeholder="<?php echo esc_attr__('Nome, igreja, cidade, curso...', 'sdd'); ?>">
            </label>
            <label>
                <span><?php echo esc_html__('Status', 'sdd'); ?></span>
                <select name="status">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <option value="Ativo"><?php echo esc_html__('Ativo', 'sdd'); ?></option>
                    <option value="Parado"><?php echo esc_html__('Parado', 'sdd'); ?></option>
                    <option value="Trancado"><?php echo esc_html__('Trancado', 'sdd'); ?></option>
                    <option value="Cancelado"><?php echo esc_html__('Cancelado', 'sdd'); ?></option>
                    <option value="Concluído"><?php echo esc_html__('Concluído', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Última atividade', 'sdd'); ?></span>
                <select name="activity">
                    <option value=""><?php echo esc_html__('Qualquer data', 'sdd'); ?></option>
                    <option value="7"><?php echo esc_html__('Inativo há 7+ dias', 'sdd'); ?></option>
                    <option value="30"><?php echo esc_html__('Inativo há 30+ dias', 'sdd'); ?></option>
                    <option value="60"><?php echo esc_html__('Inativo há 60+ dias', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Curso', 'sdd'); ?></span>
                <select name="course_id">
                    <option value=""><?php echo esc_html__('Todos os cursos', 'sdd'); ?></option>
                    <?php foreach (sdd_get_tutor_courses() as $course) : ?>
                        <option value="<?php echo esc_attr($course->ID); ?>"><?php echo esc_html($course->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <div class="sdd-results" data-sdd-results aria-live="polite">
            <div class="sdd-loading"><?php echo esc_html__('Carregando dados...', 'sdd'); ?></div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function sdd_current_user_can_view_dashboard() {
    $user = wp_get_current_user();
    $staff_roles = ['administrator', 'editor', 'tutor_instructor', 'instructor'];
    $has_staff_role = array_intersect($staff_roles, (array) $user->roles);
    $allowed = current_user_can('manage_options') || current_user_can('edit_posts') || !empty($has_staff_role);

    /**
     * Filter whether the current user can view the IBBI staff dashboard.
     *
     * @param bool $allowed Access decision.
     */
    return (bool) apply_filters('sdd_current_user_can_view_dashboard', $allowed);
}

function sdd_sanitize_dashboard_filters($input) {
    return [
        'search' => isset($input['search']) ? sanitize_text_field(wp_unslash($input['search'])) : '',
        'status' => isset($input['status']) ? sanitize_text_field(wp_unslash($input['status'])) : '',
        'activity' => isset($input['activity']) ? absint($input['activity']) : 0,
        'course_id' => isset($input['course_id']) ? absint($input['course_id']) : 0,
    ];
}

function sdd_get_student_users() {
    return get_users([
        'role__in' => ['subscriber', 'student', 'tutor_student'],
        'orderby' => 'display_name',
        'order' => 'ASC',
        'number' => 500,
    ]);
}

function sdd_get_tutor_courses() {
    if (!function_exists('tutor_utils')) {
        return [];
    }

    $courses = get_posts([
        'post_type' => 'courses',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    return is_array($courses) ? $courses : [];
}

function sdd_get_student_name($user) {
    $name = trim($user->first_name . ' ' . $user->last_name);
    return $name ?: $user->display_name;
}

function sdd_get_user_meta_first($user_id, array $keys, $default = '') {
    foreach ($keys as $key) {
        $value = get_user_meta($user_id, $key, true);
        if ('' !== $value && null !== $value) {
            return is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : $value;
        }
    }

    return $default;
}

function sdd_get_student_last_activity($user_id) {
    $last_login = absint(get_user_meta($user_id, 'last_login', true));
    $last_activity = $last_login;

    if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'get_last_activity')) {
        $activity = tutor_utils()->get_last_activity($user_id);
        if ($activity) {
            $last_activity = max($last_activity, strtotime($activity));
        }
    }

    return $last_activity;
}

function sdd_get_student_courses($user_id) {
    global $wpdb;

    if (!function_exists('tutor_utils')) {
        return [];
    }

    $query = tutor_utils()->get_enrolled_courses_by_user($user_id);
    $courses = [];

    if (!$query || !$query->have_posts()) {
        return [];
    }

    foreach ($query->posts as $course) {
        if ('publish' !== $course->post_status) {
            continue;
        }

        $course_id = absint($course->ID);
        $progress = absint(tutor_utils()->get_course_completed_percent($course_id, $user_id));
        $completed = (bool) tutor_utils()->is_completed_course($course_id, $user_id);
        $enrolled_at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_date FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_author = %d AND post_parent = %d ORDER BY post_date DESC LIMIT 1",
                $user_id,
                $course_id
            )
        );
        $completed_at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT comment_date FROM {$wpdb->comments} WHERE comment_type = 'course_completed' AND user_id = %d AND comment_post_ID = %d ORDER BY comment_date DESC LIMIT 1",
                $user_id,
                $course_id
            )
        );

        $courses[] = [
            'id' => $course_id,
            'title' => get_the_title($course_id),
            'progress' => min(100, $progress),
            'status' => $completed ? 'Concluído' : ($progress > 0 ? 'Em andamento' : 'Não iniciado'),
            'completed' => $completed,
            'enrolled_at' => $enrolled_at,
            'completed_at' => $completed_at,
        ];
    }

    return $courses;
}

function sdd_get_student_summary($user) {
    $user_id = $user->ID;
    $courses = sdd_get_student_courses($user_id);
    $course_count = count($courses);
    $completed_count = count(array_filter($courses, static function ($course) {
        return !empty($course['completed']);
    }));
    $average_progress = 0;

    if ($course_count > 0) {
        $average_progress = round(array_sum(wp_list_pluck($courses, 'progress')) / $course_count);
    }

    $last_activity = sdd_get_student_last_activity($user_id);
    $enrolled_dates = array_filter(wp_list_pluck($courses, 'enrolled_at'));
    $first_enrolled_at = '';

    if ($enrolled_dates) {
        usort($enrolled_dates, static function ($a, $b) {
            return strtotime($a) <=> strtotime($b);
        });
        $first_enrolled_at = reset($enrolled_dates);
    }

    $student = [
        'id' => $user_id,
        'name' => sdd_get_student_name($user),
        'email' => $user->user_email,
        'whatsapp' => sdd_get_user_meta_first($user_id, ['_bi_whatsapp', 'phone', 'billing_phone']),
        'birthdate' => sdd_get_user_meta_first($user_id, ['data_de_nascimento', 'birthdate']),
        'church' => sdd_get_user_meta_first($user_id, ['_bi_church', 'church_name', 'igreja']),
        'pastor' => sdd_get_user_meta_first($user_id, ['_bi_pastor', 'Pastor_name', 'pastor']),
        'city' => sdd_get_user_meta_first($user_id, ['cidade', 'city']),
        'state' => sdd_get_user_meta_first($user_id, ['estado', 'state']),
        'status' => sdd_get_user_meta_first($user_id, ['_bi_student_status', 'status_aluno'], 'Ativo'),
        'level' => sdd_get_user_meta_first($user_id, ['_bi_level', 'nivel', 'nivel_associacao']),
        'payment' => sdd_get_user_meta_first($user_id, ['_bi_payment_status', 'pagamento', 'es']),
        'supervisor' => sdd_get_user_meta_first($user_id, ['_bi_supervisor', 'supervisor']),
        'co_validation' => sdd_get_user_meta_first($user_id, ['_bi_covalidation_status', 'co_validacao', 'validacao']),
        'admin_notes' => sdd_get_user_meta_first($user_id, ['_bi_admin_notes', 'anotacoes_personalizadas', 'sdd_student_notes']),
        'testimony' => sdd_get_user_meta_first($user_id, ['salvacao', 'testemunho']),
        'theology' => sdd_get_user_meta_first($user_id, ['Theology_stance', 'teologia']),
        'first_enrolled_at' => $first_enrolled_at,
        'first_enrolled_label' => $first_enrolled_at ? date_i18n('d/m/Y', strtotime($first_enrolled_at)) : 'Sem registro',
        'last_activity' => $last_activity,
        'last_activity_label' => $last_activity ? date_i18n('d/m/Y', $last_activity) : 'Sem registro',
        'course_count' => $course_count,
        'completed_count' => $completed_count,
        'average_progress' => $average_progress,
        'courses' => $courses,
    ];

    $student['signals'] = sdd_get_student_signals($student);

    return $student;
}

function sdd_get_student_signals($student) {
    $signals = [];

    if (!$student['last_activity']) {
        $signals[] = 'Sem atividade registrada';
    } else {
        $inactive_days = floor((time() - $student['last_activity']) / DAY_IN_SECONDS);
        if ($inactive_days >= 60) {
            $signals[] = 'Inativo há 60+ dias';
        } elseif ($inactive_days >= 30) {
            $signals[] = 'Inativo há 30+ dias';
        } elseif ($inactive_days >= 14) {
            $signals[] = 'Inativo há 14+ dias';
        }
    }

    if ($student['course_count'] > 0 && 0 === $student['average_progress']) {
        $signals[] = 'Matriculado sem progresso';
    } elseif ($student['course_count'] > 0 && $student['average_progress'] < 35) {
        $signals[] = 'Progresso baixo';
    } elseif ($student['course_count'] > 0 && $student['average_progress'] >= 85 && $student['completed_count'] < $student['course_count']) {
        $signals[] = 'Perto de concluir';
    }

    if (0 === strcasecmp($student['status'], 'Parado') || 0 === strcasecmp($student['status'], 'Trancado')) {
        $signals[] = 'Requer acompanhamento';
    }

    return array_values(array_unique($signals));
}

function sdd_student_matches_filters($student, $filters) {
    if ($filters['status'] && 0 !== strcasecmp($student['status'], $filters['status'])) {
        return false;
    }

    if ($filters['activity']) {
        if (!$student['last_activity']) {
            return true;
        }

        $cutoff = time() - ($filters['activity'] * DAY_IN_SECONDS);
        if ($student['last_activity'] > $cutoff) {
            return false;
        }
    }

    if ($filters['course_id']) {
        $course_ids = wp_list_pluck($student['courses'], 'id');
        if (!in_array($filters['course_id'], $course_ids, true)) {
            return false;
        }
    }

    if ($filters['search']) {
        $haystack = strtolower(
            implode(
                ' ',
                [
                    $student['name'],
                    $student['email'],
                    $student['church'],
                    $student['city'],
                    $student['state'],
                    $student['theology'],
                    implode(' ', wp_list_pluck($student['courses'], 'title')),
                ]
            )
        );

        if (false === strpos($haystack, strtolower($filters['search']))) {
            return false;
        }
    }

    return true;
}

function sdd_get_filtered_students($filters) {
    $students = [];

    foreach (sdd_get_student_users() as $user) {
        $student = sdd_get_student_summary($user);
        if (sdd_student_matches_filters($student, $filters)) {
            $students[] = $student;
        }
    }

    return $students;
}

function sdd_get_overview_data($filters) {
    $students = sdd_get_filtered_students($filters);
    $total = count($students);
    $inactive_30 = 0;
    $needs_followup = 0;
    $progress_sum = 0;

    foreach ($students as $student) {
        $progress_sum += $student['average_progress'];

        if (!$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS)) {
            $inactive_30++;
        }

        if ($student['average_progress'] < 30 || 0 === strcasecmp($student['status'], 'Parado')) {
            $needs_followup++;
        }
    }

    return [
        'html' => sdd_render_overview($students, [
            'total' => $total,
            'inactive_30' => $inactive_30,
            'needs_followup' => $needs_followup,
            'average_progress' => $total ? round($progress_sum / $total) : 0,
        ]),
    ];
}

function sdd_get_person_view_data($filters) {
    return [
        'html' => sdd_render_person_view(sdd_get_filtered_students($filters)),
    ];
}

function sdd_get_course_view_data($filters) {
    $students = sdd_get_filtered_students($filters);
    $courses = [];

    foreach ($students as $student) {
        foreach ($student['courses'] as $course) {
            if (!isset($courses[$course['id']])) {
                $courses[$course['id']] = [
                    'id' => $course['id'],
                    'title' => $course['title'],
                    'students' => 0,
                    'completed' => 0,
                    'progress_sum' => 0,
                    'stalled' => 0,
                ];
            }

            $courses[$course['id']]['students']++;
            $courses[$course['id']]['progress_sum'] += $course['progress'];

            if ($course['completed']) {
                $courses[$course['id']]['completed']++;
            }

            if (!$course['completed'] && $course['progress'] < 35) {
                $courses[$course['id']]['stalled']++;
            }
        }
    }

    uasort($courses, static function ($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });

    return [
        'html' => sdd_render_course_view(array_values($courses)),
    ];
}

function sdd_render_overview($students, $metrics) {
    ob_start();
    ?>
    <div class="sdd-metrics">
        <?php sdd_metric_card('Alunos', $metrics['total'], 'no filtro atual'); ?>
        <?php sdd_metric_card('Progresso médio', $metrics['average_progress'] . '%', 'entre alunos listados'); ?>
        <?php sdd_metric_card('Inativos 30+ dias', $metrics['inactive_30'], 'precisam de atenção'); ?>
        <?php sdd_metric_card('Follow-up', $metrics['needs_followup'], 'baixo progresso/parado'); ?>
    </div>
    <?php echo sdd_render_person_view(array_slice($students, 0, 12), 'Alunos para acompanhar'); ?>
    <?php
    return ob_get_clean();
}

function sdd_metric_card($label, $value, $hint) {
    ?>
    <article class="sdd-metric">
        <span><?php echo esc_html($label); ?></span>
        <strong><?php echo esc_html($value); ?></strong>
        <small><?php echo esc_html($hint); ?></small>
    </article>
    <?php
}

function sdd_render_person_view($students, $title = 'Alunos') {
    ob_start();
    ?>
    <div class="sdd-table-wrap">
        <h3><?php echo esc_html($title); ?></h3>
        <?php if (!$students) : ?>
            <p class="sdd-empty"><?php echo esc_html__('Nenhum aluno encontrado.', 'sdd'); ?></p>
        <?php else : ?>
            <table class="sdd-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Aluno', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Igreja / Local', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Status', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Cursos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Progresso', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Última atividade', 'sdd'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student) : ?>
                        <?php $detail_id = 'sdd-student-detail-' . absint($student['id']); ?>
                        <tr class="sdd-student-row">
                            <td>
                                <strong><?php echo esc_html($student['name']); ?></strong>
                                <span><?php echo esc_html($student['email']); ?></span>
                                <?php if ($student['whatsapp']) : ?>
                                    <a href="<?php echo esc_url('https://wa.me/' . preg_replace('/\D+/', '', $student['whatsapp'])); ?>" target="_blank" rel="noopener"><?php echo esc_html__('WhatsApp', 'sdd'); ?></a>
                                <?php endif; ?>
                                <button class="sdd-detail-toggle" type="button" data-sdd-toggle="<?php echo esc_attr($detail_id); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($detail_id); ?>">
                                    <?php echo esc_html__('Ver detalhes', 'sdd'); ?>
                                </button>
                            </td>
                            <td>
                                <?php echo esc_html($student['church'] ?: 'Não informado'); ?>
                                <span><?php echo esc_html(trim($student['city'] . ' ' . $student['state']) ?: ''); ?></span>
                            </td>
                            <td><span class="sdd-pill"><?php echo esc_html($student['status']); ?></span></td>
                            <td><?php echo esc_html($student['completed_count'] . '/' . $student['course_count']); ?></td>
                            <td><?php echo sdd_progress_bar($student['average_progress']); ?></td>
                            <td>
                                <?php echo esc_html($student['last_activity_label']); ?>
                                <?php if ($student['signals']) : ?>
                                    <div class="sdd-signal-list">
                                        <?php foreach ($student['signals'] as $signal) : ?>
                                            <span class="sdd-signal"><?php echo esc_html($signal); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="<?php echo esc_attr($detail_id); ?>" class="sdd-detail-row" hidden>
                            <td colspan="6">
                                <div class="sdd-student-detail">
                                    <section>
                                        <h4><?php echo esc_html__('Resumo do aluno', 'sdd'); ?></h4>
                                        <dl class="sdd-detail-list">
                                            <div><dt><?php echo esc_html__('Matrícula', 'sdd'); ?></dt><dd><?php echo esc_html($student['first_enrolled_label']); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Nascimento', 'sdd'); ?></dt><dd><?php echo esc_html($student['birthdate'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Pastor', 'sdd'); ?></dt><dd><?php echo esc_html($student['pastor'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Teologia', 'sdd'); ?></dt><dd><?php echo esc_html($student['theology'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Nível', 'sdd'); ?></dt><dd><?php echo esc_html($student['level'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Pagamento', 'sdd'); ?></dt><dd><?php echo esc_html($student['payment'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Supervisor', 'sdd'); ?></dt><dd><?php echo esc_html($student['supervisor'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Co-validação', 'sdd'); ?></dt><dd><?php echo esc_html($student['co_validation'] ?: 'Não informado'); ?></dd></div>
                                        </dl>
                                        <?php if ($student['admin_notes']) : ?>
                                            <div class="sdd-note">
                                                <strong><?php echo esc_html__('Últimas notícias', 'sdd'); ?></strong>
                                                <p><?php echo nl2br(esc_html($student['admin_notes'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                    <section>
                                        <h4><?php echo esc_html__('Progresso nas matérias', 'sdd'); ?></h4>
                                        <?php if ($student['courses']) : ?>
                                    <div class="sdd-course-list">
                                        <?php foreach ($student['courses'] as $course) : ?>
                                            <div>
                                                <strong><?php echo esc_html($course['title']); ?></strong>
                                                <span><?php echo esc_html($course['status'] . ' · ' . $course['progress'] . '%'); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                        <?php else : ?>
                                            <p class="sdd-empty sdd-empty--inline"><?php echo esc_html__('Nenhuma matrícula encontrada.', 'sdd'); ?></p>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sdd_render_course_view($courses) {
    ob_start();
    ?>
    <div class="sdd-table-wrap">
        <h3><?php echo esc_html__('Matérias', 'sdd'); ?></h3>
        <?php if (!$courses) : ?>
            <p class="sdd-empty"><?php echo esc_html__('Nenhuma matéria encontrada.', 'sdd'); ?></p>
        <?php else : ?>
            <table class="sdd-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Matéria', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Alunos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Concluídos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Progresso médio', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Possível gargalo', 'sdd'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course) : ?>
                        <?php $average = $course['students'] ? round($course['progress_sum'] / $course['students']) : 0; ?>
                        <tr>
                            <td><strong><?php echo esc_html($course['title']); ?></strong></td>
                            <td><?php echo esc_html($course['students']); ?></td>
                            <td><?php echo esc_html($course['completed']); ?></td>
                            <td><?php echo sdd_progress_bar($average); ?></td>
                            <td><?php echo esc_html($course['stalled'] . ' aluno(s) abaixo de 35%'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sdd_progress_bar($percent) {
    $percent = max(0, min(100, absint($percent)));
    return sprintf(
        '<div class="sdd-progress" aria-label="%1$s"><span style="width:%2$d%%"></span><strong>%2$d%%</strong></div>',
        esc_attr(sprintf(__('%d por cento', 'sdd'), $percent)),
        $percent
    );
}
