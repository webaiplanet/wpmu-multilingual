(function() {
    'use strict';

    function initUnavailableModal() {
        var modal = document.getElementById('wpmu-ml-language-notice-modal');
        if (!modal) return;

        var titleEl = document.getElementById('wpmu-ml-language-notice-title');
        var textEl = document.getElementById('wpmu-ml-language-notice-text');
        var lastFocus = null;

        function openModal(message, trigger) {
            lastFocus = trigger || document.activeElement;
            var language = trigger ? (trigger.getAttribute('data-wpmu-ml-language') || '') : '';
            if (titleEl) {
                titleEl.textContent = language ? ('该页面 ' + language + ' 语言版本暂未发布') : '该语言版本暂未发布';
            }
            if (textEl && message) {
                textEl.textContent = message;
            }
            modal.classList.add('is-active');
            modal.setAttribute('aria-hidden', 'false');
            var closeBtn = modal.querySelector('[data-wpmu-ml-modal-close]');
            if (closeBtn && typeof closeBtn.focus === 'function') {
                closeBtn.focus({ preventScroll: true });
            }
        }

        function closeModal() {
            modal.classList.remove('is-active');
            modal.setAttribute('aria-hidden', 'true');
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus({ preventScroll: true });
            }
        }

        document.addEventListener('click', function(event) {
            var trigger = event.target.closest && event.target.closest('[data-wpmu-ml-unavailable="1"]');
            if (trigger) {
                event.preventDefault();
                openModal(trigger.getAttribute('data-wpmu-ml-message') || '该语言版本暂未发布。', trigger);
                return;
            }
            if (event.target === modal || (event.target.closest && event.target.closest('[data-wpmu-ml-modal-close]'))) {
                event.preventDefault();
                closeModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.classList.contains('is-active')) {
                closeModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUnavailableModal);
    } else {
        initUnavailableModal();
    }
})();
