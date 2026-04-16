/* FYP Vault - Global JS */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5s
    document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Confirm destructive actions
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    // Quick navigation controls
    var backBtn = document.getElementById('js-nav-back');
    var forwardBtn = document.getElementById('js-nav-forward');

    if (backBtn) {
        backBtn.addEventListener('click', function() {
            if (window.history.length > 1) {
                window.history.back();
            }
        });
        if (window.history.length <= 1) {
            backBtn.disabled = true;
            backBtn.title = 'No previous page in this tab yet';
        }
    }

    if (forwardBtn) {
        forwardBtn.addEventListener('click', function() {
            window.history.forward();
        });
    }

    // Rotate short tips to make navigation feel welcoming
    var tipEl = document.getElementById('js-fun-tip');
    if (tipEl) {
        var tips = [
            'Tip: Use Back and Forward to move quickly through your workflow.',
            'Shortcut: Open your profile from the top-right menu anytime.',
            'Tip: Check notifications often for feedback and approvals.',
            'Nice flow: Dashboard gives you role-based quick actions first.',
            'Pro move: Use Vault search filters to find archived projects faster.'
        ];
        var i = 0;
        setInterval(function() {
            i = (i + 1) % tips.length;
            tipEl.textContent = tips[i];
        }, 7000);
    }
});
