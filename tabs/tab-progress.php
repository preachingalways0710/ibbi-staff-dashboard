<?php
defined('ABSPATH') || exit;

// tab-progress.php — cleaned: correct Tutor columns, no undefined vars, columns match header

$student_id = intval($_GET['student_id'] ?? 0);
if (!$student_id) {
    echo '<p>No student selected.</p>';
    return;
}

global $wpdb;

/** -----------------------------------------------------------------
 * Save editable Academic Status fields
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['academic_status_nonce'])
    && wp_verify_nonce($_POST['academic_status_nonce'], 'save_academic_status')) {

    update_user_meta($student_id, 'status_aluno', sanitize_text_field($_POST['status_aluno'] ?? ''));
    update_user_meta($student_id, 'pagamento', sanitize_text_field($_POST['pagamento'] ?? ''));
    update_user_meta($student_id, 'co_validacao', sanitize_text_field($_POST['co_validacao'] ?? ''));
    update_user_meta($student_id, 'anotacoes_personalizadas', sanitize_textarea_field($_POST['anotacoes_personalizadas'] ?? ''));

    echo '<div class="updated notice"><p>Dados acadêmicos atualizados com sucesso.</p></div>';
}

function sdd_meta($user_id, $key) {
    return get_user_meta($user_id, $key, true);
}

?>
<hr>
<h3>Progresso Acadêmico (Tutor LMS)</h3>
<?php
if (!function_exists('tutor_utils')) {
    echo '<p><em>Tutor LMS não está ativo ou carregado corretamente.</em></p>';
    return;
}

// Get enrolled courses (WP_Query object)
$courses = tutor_utils()->get_enrolled_courses_by_user($student_id);

$enrolled_courses = [];
if ($courses && $courses->have_posts()) {
    foreach ($courses->posts as $course) {
        if ($course->post_status !== 'publish') { continue; }
        $enrolled_courses[] = $course;
    }
}

// Quick summary
$total_courses = count($enrolled_courses);
$completed_courses = 0;
foreach ($enrolled_courses as $course) {
    if (tutor_utils()->is_completed_course($course->ID, $student_id)) {
        $completed_courses++;
    }
}

echo '<div style="margin-bottom:20px;padding:10px;background:#f8f8f8;border-radius:6px;">'
   . '<strong>Resumo:</strong> ' . intval($total_courses) . ' curso(s) matriculado(s), '
   . intval($completed_courses) . ' concluído(s)'
   . '</div>';

if ($total_courses > 0) {
    echo '<table id="academic-progress-table" class="widefat fixed striped">';
    echo '<thead><tr>'
       . '<th>Thumb</th>'
       . '<th>Curso</th>'
       . '<th>Progresso</th>'
       . '<th>Status</th>'
       . '<th>Nota Final</th>'
       . '<th>Data da Matrícula</th>'
       . '<th>Data de Conclusão</th>'
       . '</tr></thead><tbody>';

    // Ensure row rendering matches headers
    foreach ($enrolled_courses as $course) {
        $course_id = (int) $course->ID;

        // Tutor progress & status
        $progress     = (int) tutor_utils()->get_course_completed_percent($course_id, $student_id); // 0..100
        $is_completed = (bool) tutor_utils()->is_completed_course($course_id, $student_id);

        // Thumb
        $thumb = get_the_post_thumbnail($course_id, 'thumbnail', ['style' => 'width:60px;height:auto;border-radius:4px']);

        // Enrollment date
        $enrol_date = $wpdb->get_var($wpdb->prepare(
            "SELECT post_date
             FROM {$wpdb->posts}
             WHERE post_type = 'tutor_enrolled'
               AND post_author = %d
               AND post_parent = %d
             ORDER BY post_date DESC
             LIMIT 1",
            $student_id,
            $course_id
        ));
        $matricula = $enrol_date ? date_i18n('d/m/Y H:i', strtotime($enrol_date)) : '-';

        // QUIZZES: attempts & passed; also compute average score over attempts
        $quiz_attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT earned_marks, total_marks, attempt_status, result
             FROM {$wpdb->prefix}tutor_quiz_attempts
             WHERE user_id = %d AND course_id = %d",
            $student_id,
            $course_id
        ));

        $attempt_count = 0;
        $passed_count  = 0;
        $sum_earned    = 0.0;
        $sum_total     = 0.0;

        if ($quiz_attempts) {
            foreach ($quiz_attempts as $qa) {
                $attempt_count++;
                if (strtolower((string)$qa->result) === 'pass') {
                    $passed_count++;
                }
                $sum_earned += (float) $qa->earned_marks;
                $sum_total  += (float) $qa->total_marks;
            }
        }

        $avg_score_percent = ($sum_total > 0) ? round(($sum_earned / $sum_total) * 100, 1) : '-';

        // Course completion date from wp_comments (course_completed)
        $completion_date = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_date
             FROM {$wpdb->comments}
             WHERE comment_type = 'course_completed'
               AND user_id = %d
               AND comment_post_ID = %d
             ORDER BY comment_date DESC
             LIMIT 1",
            $student_id,
            $course_id
        ));
        $completion = $completion_date ? date_i18n('d/m/Y H:i', strtotime($completion_date)) : '-';

        // ------- RENDER EXACTLY 7 COLUMNS (match <thead>) -------
        echo '<tr>';

        // 1) Thumb
        echo '<td>' . ($thumb ?: '-') . '</td>';

        // 2) Curso
        echo '<td>' . esc_html($course->post_title) . '</td>';

        // 3) Progresso
        echo '<td><div style="min-width:120px">'
           . '<div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:18px;width:100%">'
           . '<div style="background:#4caf50;height:18px;width:' . $progress . '%"></div>'
           . '</div>'
           . '<span style="font-size:12px">' . $progress . '%</span>'
           . '</div></td>';

        // 4) Status
        echo '<td>' . ($is_completed ? 'Concluído' : 'Em andamento') . '</td>';

        // 5) Nota Final (avg over attempts)
        echo '<td>' . (is_numeric($avg_score_percent) ? number_format($avg_score_percent, 1) . '%' : '-') . '</td>';

        // 6) Data da Matrícula
        echo '<td>' . esc_html($matricula) . '</td>';

        // 7) Data de Conclusão
        echo '<td>' . esc_html($completion) . '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
}
?>

<h3>Search and Filter Students</h3>
<form id="student-filter-form">
    <label for="status-filter">Status:</label>
    <select id="status-filter" name="status">
        <option value="">All</option>
        <option value="Ativo">Ativo</option>
        <option value="Parado">Parado</option>
    </select>

    <label for="course-filter">Course:</label>
    <input type="text" id="course-filter" name="course" placeholder="Enter course name">

    <button type="button" id="apply-filters">Apply Filters</button>
</form>

<div id="student-results">
    <!-- Results will be dynamically loaded here -->
</div>

<script>
(function() {
    document.getElementById('apply-filters').addEventListener('click', function() {
        const status = document.getElementById('status-filter').value;
        const course = document.getElementById('course-filter').value;

        fetch('path-to-your-ajax-handler', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ status, course })
        })
        .then(response => response.json())
        .then(data => {
            const resultsDiv = document.getElementById('student-results');
            resultsDiv.innerHTML = '';

            if (data.length > 0) {
                const table = document.createElement('table');
                table.className = 'widefat fixed striped';

                const thead = document.createElement('thead');
                thead.innerHTML = '<tr><th>Name</th><th>Status</th><th>Course</th></tr>';
                table.appendChild(thead);

                const tbody = document.createElement('tbody');
                data.forEach(student => {
                    const row = document.createElement('tr');
                    row.innerHTML = `<td>${student.name}</td><td>${student.status}</td><td>${student.course}</td>`;
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);

                resultsDiv.appendChild(table);
            } else {
                resultsDiv.innerHTML = '<p>No results found.</p>';
            }
        });
    });
})();
</script>

<script>
(function(){
  const table = document.getElementById('academic-progress-table');
  if (!table || !table.tHead || !table.tBodies.length) return;

  const getCellText = (tr, idx) => (tr.children[idx]?.innerText || '').trim();

  const parseNumber = v => {
    const n = parseFloat((v || '').replace(/[^\\d.\-]/g, ''));
    return isNaN(n) ? 0 : n;
  };

  const parsePercent = v => parseNumber(v); // e.g., "87.5%" -> 87.5

  const parseDateBR = v => {
    // Formats like "dd/mm/yyyy hh:mm" or "dd/mm/yyyy"
    const m = (v || '').match(/(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
    if (!m) return 0;
    const d = m[1], mo = m[2], y = m[3], h = m[4] || '00', mi = m[5] || '00';
    // Interpret as local time; if you prefer UTC, append 'Z'
    const dt = new Date(`${y}-${mo}-${d}T${h}:${mi}:00`);
    return dt.getTime() || 0;
  };

  const quizScore = v => {
    // "passed/attempted": sort by pass ratio; falls back to attempted count
    const parts = (v || '').split('/');
    const p = parseInt(parts[0], 10) || 0;
    const a = parseInt(parts[1], 10) || 0;
    return a > 0 ? (p / a) : -1;
  };

  // Define comparator per column index:
  // 0 Thumb (skip), 1 Curso, 2 Progresso, 3 Status,
  // 4 Nota Final, 5 Data matrícula, 6 Quizzes, 7 Data conclusão
  const comparators = {
    1: (a,b) => a.localeCompare(b, undefined, {sensitivity:'base'}),
    2: (a,b) => parsePercent(a) - parsePercent(b),
    3: (a,b) => a.localeCompare(b, undefined, {sensitivity:'base'}),
    4: (a,b) => parsePercent(a) - parsePercent(b),
    5: (a,b) => parseDateBR(a) - parseDateBR(b),
    6: (a,b) => quizScore(a) - quizScore(b),
    7: (a,b) => parseDateBR(a) - parseDateBR(b)
  };

  const ths = table.tHead.rows[0].cells;
  for (let i = 0; i < ths.length; i++) {
    if (!comparators[i]) continue; // skip unsortable columns like Thumb
    let asc = true;
    ths[i].style.cursor = 'pointer';
    ths[i].title = 'Clique para ordenar';

    ths[i].addEventListener('click', () => {
      const rows = Array.from(table.tBodies[0].rows);
      rows.sort((r1, r2) => {
        const v1 = getCellText(r1, i);
        const v2 = getCellText(r2, i);
        const cmp = comparators[i](v1, v2);
        return asc ? cmp : -cmp;
      });
      rows.forEach(r => table.tBodies[0].appendChild(r));
      asc = !asc;
    });
  }
})();
</script>
