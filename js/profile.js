(function () {
    var API = 'api/index.php';
    var NAME_RE = /^[a-z0-9_]{3,20}$/i;

    var css = document.createElement('style');
    css.textContent =
        '#profileOverlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);font-family:Georgia,"Times New Roman",serif;color:#f3e9e4}' +
        '#profileCard{width:min(420px,90vw);max-height:90vh;overflow-y:auto;padding:28px;border-radius:14px;background:rgba(40,30,34,.96);border:1px solid rgba(255,255,255,.15);box-shadow:0 10px 40px rgba(0,0,0,.6)}' +
        '#profileCard h2{margin:0 0 18px;font-size:24px}' +
        '#profileCard label{display:block;margin:12px 0 4px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.75}' +
        '#profileCard input,#profileCard select{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.35);color:inherit;font:inherit;font-size:16px}' +
        '#profileCard .hint{margin:4px 0 0;font-size:12px;opacity:.6}' +
        '#profileMsg{min-height:20px;margin:14px 0 4px;font-size:14px}' +
        '#profileMsg.err{color:#ff8a80}#profileMsg.ok{color:#9be3a4}' +
        '#profileCard .row{display:flex;gap:10px;margin-top:10px}' +
        '#profileCard button{flex:1;padding:10px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:inherit;font:inherit;font-size:16px;cursor:pointer}' +
        '#profileCard button:hover{background:rgba(255,255,255,.2)}' +
        '#profileCard button.primary{background:#b94a5c;border-color:#b94a5c}' +
        '#profileCard button.danger{border-color:#ff8a80;color:#ff8a80;background:transparent}' +
        '#profileCard hr{border:0;border-top:1px solid rgba(255,255,255,.15);margin:20px 0 4px}' +
        '.profileName{text-decoration:underline;text-underline-offset:3px;cursor:pointer}' ;
    document.head.appendChild(css);

    function call(method, path, body) {
        return fetch(API + path, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok) { throw new Error(data.error || 'Request failed (' + r.status + ')'); }
                return data;
            });
        });
    }

    function openEditor(me, nameEl) {
        if (document.getElementById('profileOverlay')) { return; }

        var overlay = document.createElement('div');
        overlay.id = 'profileOverlay';
        overlay.innerHTML =
            '<div id="profileCard">' +
            '<h2>Edit profile</h2>' +
            '<label for="pfUser">Username</label><input id="pfUser" maxlength="20" autocomplete="off">' +
            '<label for="pfAge">Age group</label><select id="pfAge"><option value="">Not set</option></select>' +
            '<label for="pfNew">New password</label><input id="pfNew" type="password" maxlength="72" autocomplete="new-password">' +
            '<p class="hint">Leave blank to keep your current password.</p>' +
            '<label for="pfCur">Current password</label><input id="pfCur" type="password" maxlength="72" autocomplete="current-password">' +
            '<p class="hint">Only needed when you set a new password.</p>' +
            '<div id="profileMsg"></div>' +
            '<div class="row"><button id="pfClose" type="button">Close</button>' +
            '<button id="pfSave" class="primary" type="button">Update</button></div>' +
            '<hr>' +
            '<div class="row"><button id="pfDelete" class="danger" type="button">Delete account</button></div>' +
            '</div>';
        document.body.appendChild(overlay);

        var user = overlay.querySelector('#pfUser');
        var age = overlay.querySelector('#pfAge');
        var newPw = overlay.querySelector('#pfNew');
        var curPw = overlay.querySelector('#pfCur');
        var msg = overlay.querySelector('#profileMsg');
        var del = overlay.querySelector('#pfDelete');

        user.value = me.username || '';

        function say(text, cls) { msg.textContent = text; msg.className = cls || ''; }
        function close() {
            overlay.remove();
            document.removeEventListener('keydown', onKey, true);
        }
        function onKey(e) { if (e.key === 'Escape') { close(); } }
        document.addEventListener('keydown', onKey, true);

        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        overlay.querySelector('#pfClose').addEventListener('click', close);

        call('GET', '/stats/age-groups').then(function (res) {
            res.age_groups.forEach(function (g) {
                var o = document.createElement('option');
                o.value = g;
                o.textContent = g;
                age.appendChild(o);
            });
            age.value = me.age_group || '';
        }).catch(function () { say('Could not load age groups', 'err'); });

        overlay.querySelector('#pfSave').addEventListener('click', function () {
            if (newPw.value !== '' && curPw.value === '') {
                say('Enter your current password to set a new one', 'err');
                return;
            }
            say('Saving...');
            call('POST', '/auth/update-profile', {
                username: user.value,
                age_group: age.value,
                new_password: newPw.value,
                current_password: curPw.value
            }).then(function (res) {
                me = res;
                user.value = res.username;
                newPw.value = '';
                curPw.value = '';
                if (nameEl) { nameEl.textContent = res.username; }
                document.dispatchEvent(new CustomEvent('profile-updated', { detail: res }));
                say('Profile updated', 'ok');
            }).catch(function (e) { say(e.message, 'err'); });
        });

        var armed = false;
        del.addEventListener('click', function () {
            if (!armed) {
                armed = true;
                del.textContent = 'Click again to delete forever';
                say('This deletes your account and all your saves.', 'err');
                setTimeout(function () { armed = false; del.textContent = 'Delete account'; }, 5000);
                return;
            }
            call('POST', '/auth/delete-account').then(function () {
                location.reload();
            }).catch(function (e) { say(e.message, 'err'); });
        });
    }

    // Clicking the username in the top-right bar opens the editor.
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || t.children.length || t.closest('#profileOverlay')) { return; }
        var text = (t.textContent || '').trim();
        if (!NAME_RE.test(text)) { return; }
        var box = t.getBoundingClientRect();
        if (box.top > 120 || box.left < window.innerWidth / 2) { return; }

        call('GET', '/auth/me').then(function (me) {
            if (me.username === text.toLowerCase()) { openEditor(me, t); }
        }).catch(function () {});
    }, true);

        // Underline the username in the top-right bar so it looks clickable.
    var barTimer = null;

    function decorateBar() {
        barTimer = null;
        if (document.querySelector('.profileName')) { return; }

        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue.trim() !== 'Log out') { continue; }

            var bar = node.parentElement && node.parentElement.parentElement;
            if (!bar) { return; }

            // The username is the first thing in the bar that has any text.
            for (var n = bar.firstChild; n; n = n.nextSibling) {
                var txt = (n.textContent || '').trim();
                if (!txt) { continue; }

                if (n.nodeType === 3) {                       // plain text: wrap it in a span
                    var span = document.createElement('span');
                    n.parentNode.insertBefore(span, n);
                    span.appendChild(n);
                    n = span;
                }
                n.classList.add('profileName');
                n.title = 'Edit profile';
                return;
            }
            return;
        }
    }

    new MutationObserver(function () {
        if (!barTimer) { barTimer = setTimeout(decorateBar, 200); }
    }).observe(document.body, { childList: true, subtree: true });
    decorateBar();

})();