(function () {
  const dashboards = document.querySelectorAll('[data-sdd-dashboard]');

  dashboards.forEach((dashboard) => {
    const tabs = Array.from(dashboard.querySelectorAll('[data-sdd-view]'));
    const filters = dashboard.querySelector('[data-sdd-filters]');
    const clearFilters = dashboard.querySelector('[data-sdd-clear-filters]');
    const exportButton = dashboard.querySelector('[data-sdd-export]');
    const results = dashboard.querySelector('[data-sdd-results]');
    let activeView = 'overview';
    let debounceTimer = null;

    const setLoading = () => {
      results.innerHTML = '<div class="sdd-loading">' + sddDashboard.labels.loading + '</div>';
    };

    const formDataForRequest = () => {
      const data = new FormData(filters);
      data.append('action', 'sdd_dashboard_data');
      data.append('nonce', sddDashboard.nonce);
      data.append('view', activeView);
      return data;
    };

    const loadDashboard = () => {
      setLoading();

      fetch(sddDashboard.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formDataForRequest()
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success || !payload.data || !payload.data.html) {
            throw new Error('Invalid dashboard response');
          }

          results.innerHTML = payload.data.html;
        })
        .catch(() => {
          results.innerHTML = '<p class="sdd-empty">' + sddDashboard.labels.error + '</p>';
        });
    };

    results.addEventListener('click', (event) => {
      const toggle = event.target.closest('[data-sdd-toggle]');

      if (!toggle) {
        return;
      }

      const detail = document.getElementById(toggle.dataset.sddToggle);

      if (!detail) {
        return;
      }

      const isOpening = detail.hasAttribute('hidden');
      detail.toggleAttribute('hidden', !isOpening);
      toggle.setAttribute('aria-expanded', isOpening ? 'true' : 'false');
      toggle.textContent = isOpening ? 'Ocultar detalhes' : 'Ver detalhes';
      toggle.closest('tr')?.classList.toggle('is-expanded', isOpening);
    });

    results.addEventListener('submit', (event) => {
      const form = event.target.closest('[data-sdd-staff-form]');

      if (!form) {
        return;
      }

      event.preventDefault();

      const status = form.querySelector('[data-sdd-save-status]');
      const button = form.querySelector('button[type="submit"]');
      const data = new FormData(form);
      data.append('action', 'sdd_save_student_meta');
      data.append('nonce', sddDashboard.nonce);

      if (status) {
        status.textContent = 'Salvando...';
      }
      if (button) {
        button.disabled = true;
      }

      fetch(sddDashboard.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success) {
            throw new Error('Save failed');
          }
          if (status) {
            status.textContent = 'Salvo';
          }
          window.setTimeout(loadDashboard, 650);
        })
        .catch(() => {
          if (status) {
            status.textContent = 'Erro ao salvar';
          }
        })
        .finally(() => {
          if (button) {
            button.disabled = false;
          }
        });
    });

    const debounceLoad = () => {
      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(loadDashboard, 250);
    };

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        activeView = tab.dataset.sddView || 'overview';

        tabs.forEach((button) => {
          button.classList.toggle('is-active', button === tab);
        });

        loadDashboard();
      });
    });

    filters.addEventListener('input', debounceLoad);
    filters.addEventListener('change', loadDashboard);

    if (clearFilters) {
      clearFilters.addEventListener('click', () => {
        filters.reset();
        loadDashboard();
      });
    }

    if (exportButton) {
      exportButton.addEventListener('click', () => {
        const data = new FormData(filters);
        data.append('action', 'sdd_export_students');
        data.append('nonce', sddDashboard.nonce);

        exportButton.disabled = true;

        fetch(sddDashboard.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: data
        })
          .then((response) => {
            if (!response.ok) {
              throw new Error('Export failed');
            }
            return response.blob();
          })
          .then((blob) => {
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'ibbi-alunos.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
          })
          .catch(() => {
            results.innerHTML = '<p class="sdd-empty">' + sddDashboard.labels.error + '</p>';
          })
          .finally(() => {
            exportButton.disabled = false;
          });
      });
    }

    loadDashboard();
  });
})();
