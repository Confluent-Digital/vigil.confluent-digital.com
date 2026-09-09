/* Vigil — comportements du back-office. */
(function () {
    'use strict';

    // Theme. localStorage peut lever (navigation privee, cookies bloques) :
    // toute lecture et toute ecriture sont gardees, et la page doit s'afficher
    // correctement sans valeur stockee.
    var read = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
    var write = function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sans effet */ } };

    var apply = function (theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        var icon = document.getElementById('themeIcon');
        if (icon) { icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; }
        var btn = document.getElementById('themeToggle');
        if (btn) { btn.title = theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'; }
    };

    // Sans preference enregistree, on suit le reglage du systeme : c'est deja
    // la reponse que l'utilisateur a donnee ailleurs. Le meme calcul est fait
    // en tete de page pour eviter le flash au chargement.
    var stored = read('vigil.theme');
    var systemDark = false;
    try { systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { /* sans effet */ }
    apply(stored || (systemDark ? 'dark' : 'light'));

    document.getElementById('themeToggle')?.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        write('vigil.theme', next);
        apply(next);
    });

    // Copie d'un lien de tracking.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.copy-btn');
        if (!btn) { return; }
        navigator.clipboard.writeText(btn.dataset.copy).then(function () {
            var icon = btn.querySelector('i');
            btn.classList.add('is-copied');
            if (icon) { icon.className = 'bi bi-check-lg'; }
            setTimeout(function () {
                btn.classList.remove('is-copied');
                if (icon) { icon.className = 'bi bi-clipboard'; }
            }, 1200);
        });
    });

    // Insertion d'une macro au curseur dans le champ le plus proche.
    document.addEventListener('click', function (e) {
        var chip = e.target.closest('.macro-chip');
        if (!chip) { return; }
        var group = chip.closest('.col-12, .mb-3, .card-body');
        var field = group && group.querySelector('input.font-monospace, textarea');
        if (!field) { return; }
        var start = field.selectionStart || field.value.length;
        field.value = field.value.slice(0, start) + chip.dataset.macro + field.value.slice(field.selectionEnd || start);
        field.focus();
    });
})();
