// =====================================================
//  GAME
//  Content warning, room stories (rooms/room{n}.json), API calls,
//  tension effects, multi-slot save/load, support screen and the
//  final statistics dashboard.
//
//  Load this AFTER rooms.js. The enterRoom() and showEnding() defined here
//  replace the placeholders in rooms.js (you can delete those two).
// =====================================================
(function () {
    'use strict';

    const API_BASE = 'api/index.php';   // change this if your API lives somewhere else
    const MAX_TENSION_POINTS = 5;       // total choice tension that fills the red vignette
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Verified against MSF's NAVH page. Re-check before submitting.
    const SUPPORT = [
        { name: 'National Anti-Violence and Sexual Harassment Helpline', detail: '24 hours', label: '1800-777-0000', tel: '18007770000' },
        { name: 'Immediate danger: Police', detail: '', label: '999', tel: '999' },
        { name: 'Samaritans of Singapore', detail: '24 hours, if you need to talk', label: '1767', tel: '1767' }
    ];

    const game = {
        sessionID: null,
        saveName: null,
        offline: false,        // true once an API call has failed
        room: null,            // the loaded room JSON
        roomId: null,
        sceneID: null,
        tensionPoints: 0,
        busy: false,
        profile: null,         // { age_group } from the personal start, or null if skipped
        user: null
    };
    const roomCache = {};

    const ROOM_DEFAULT_REASON = {
        1: 'Financial Problems',
        2: 'Adultery & Infidelity',
        3: 'Adultery & Infidelity',
        4: 'Unreasonable Behaviour'
    };
    const spotlightCache = {};

    // Sound effects played by scenes ("sfx" in the room JSON). Put your files in /audio
    // and change the file names here. A missing file is skipped (one console warning).
    const SFX_FILES = {
        shouting:       'audio/argument.mp4',      // the two of them arguing
        glass_break:    'audio/glassbreaking.mp3',
        scream:         'audio/womanscreaming.mp3',
        man_scream:     'audio/menscreaming.mp3',
        // no file yet for these three: they are skipped until you add one
        phone_buzz:     'audio/phone_buzz.mp3',
        door_slam:      'audio/door_slam.mp3',
        thrown_objects: 'audio/thrown_objects.mp3'
    };
    const sfxWarned = {};
    const activeSfx = [];     // sounds still playing, so the next scene can fade them out
    const sfxTimers = [];     // cues waiting on a delay
    let soundOn = true;
    let story = null;         // { root, body } for the open story overlay

    // ---------- small helpers ----------

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, reduceMotion ? 0 : ms); });
    }

    function showToast(message) {
        let toast = document.getElementById('saveToast');
        if (!toast) {
            toast = el('div', 'saveToast');
            toast.id = 'saveToast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(Number(toast.dataset.timer || 0));
        toast.dataset.timer = String(setTimeout(function () {
            toast.classList.remove('show');
        }, 2600));
    }

    async function api(method, path, body) {
        const res = await fetch(API_BASE + path, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok) throw new Error(data.error || ('Request failed (' + res.status + ')'));
        return data;
    }

    // Guest player + session, created the first time a choice or save is triggered
    async function ensureSession() {
        if (game.sessionID) return game.sessionID;
        const res = await api('POST', '/game/new');
        game.sessionID = res.sessionID;
        return game.sessionID;
    }

    async function loadRoom(id) {
        if (roomCache[id]) return roomCache[id];
        const res = await fetch('rooms/room' + id + '.json');
        if (!res.ok) throw new Error('Could not load rooms/room' + id + '.json');
        roomCache[id] = await res.json();
        return roomCache[id];
    }

    // ---------- sound ----------

    function setSound(on) {
        soundOn = on;
        [doorCreak, weddingSong, breathing, heartbeat].forEach(function (a) {
            if (a) a.muted = !on;
        });
    }

    // cue is "glass_break" or { id: "glass_break", delay: 700, volume: 0.8 }
    function playSfx(cue) {
        const item = typeof cue === 'string' ? { id: cue } : cue;
        const file = SFX_FILES[item.id];
        if (!soundOn || !file) return;
        sfxTimers.push(setTimeout(function () {
            const a = new Audio(file);
            a.volume = item.volume !== undefined ? item.volume : 0.9;
            activeSfx.push(a);
            a.play().catch(function (e) {
                if (!sfxWarned[item.id]) {
                    sfxWarned[item.id] = true;
                    console.warn('Sound effect not played (' + file + '):', e.message);
                }
            });
        }, item.delay || 0));
    }

    // Fade out any scene sounds still playing and cancel ones still waiting on a delay,
    // so a long clip never runs over the next scene
    function stopSfx(ms) {
        sfxTimers.splice(0).forEach(clearTimeout);
        activeSfx.splice(0).forEach(function (a) {
            if (!a.paused) fadeAudio(a, 0, ms || 400);
        });
    }

    // Short screen shake and red flash for door slams, glass breaking, and so on
    function flashImpact() {
        if (reduceMotion || !story) return;
        const root = story.root;
        root.classList.remove('impact');
        void root.offsetWidth;   // restart the animation
        root.classList.add('impact');
        setTimeout(function () { root.classList.remove('impact'); }, 700);
    }

    // Heartbeat speeds up and breathing gets louder as tension rises
    function setTensionAudio(t) {
        if (typeof heartbeat === 'undefined') return;
        heartbeat.playbackRate = 0.9 + t * 0.7;
        fadeAudio(heartbeat, 0.25 + t * 0.55, 600);
        fadeAudio(breathing, 0.1 + t * 0.6, 600);
    }

    // Back to the levels used while the room cards are showing
    function restoreCardAudio() {
        if (typeof heartbeat === 'undefined') return;
        heartbeat.playbackRate = 1;
        fadeAudio(heartbeat, 0.6, 1000);
        fadeAudio(breathing, 0.7, 1000);
    }

    // ---------- overlays ----------

    function setCardsVisible(visible) {
        ['roomChoices', 'roomPrompt', 'hubPulseBar'].forEach(function (id) {
            const n = document.getElementById(id);
            if (n) n.style.display = visible ? '' : 'none';
        });
    }

    function closeStory() {
        stopSfx(300);
        if (story) {
            story.root.remove();
            story = null;
        }
    }

    function openStory() {
        closeStory();
        const root = el('div', 'story');
        const vignette = el('div', 'storyVignette');
        const body = el('div', 'storyBody');
        root.append(vignette, body);
        document.body.appendChild(root);
        story = { root: root, body: body };
    }

    // Fills the empty top half of .story with live MySQL data for the current theme
    async function updateMidgameHud(reason) {
        if (!story || !reason) return;
        let hud = story.root.querySelector('.midgameHud');
        if (!hud) {
            hud = el('aside', 'midgameHud');
            story.root.insertBefore(hud, story.body);
        }

        try {
            let data = spotlightCache[reason];
            if (!data) {
                const res = await api('GET', '/stats/midgame?reason=' + encodeURIComponent(reason));
                data = res.spotlight;
                spotlightCache[reason] = data;
            }
            if (!data || !data.summary) return;

            hud.replaceChildren();
            const s = data.summary;
            const yoyText = s.yoy_pts === null ? '' :
                (s.yoy_pts > 0 ? ' (▲ ' + s.yoy_pts + ' pts YoY)' :
                (s.yoy_pts < 0 ? ' (▼ ' + Math.abs(s.yoy_pts) + ' pts YoY)' : ' (unchanged YoY)'));

            hud.appendChild(el('div', 'hudKicker', 'Singapore ' + data.year + ' · Live SQL Reality Check'));
            hud.appendChild(el('div', 'hudHeadline',
                data.reason + ': ' + s.share_pct + '% of divorces (' + s.count.toLocaleString() +
                ' cases, Rank #' + s.rank + ')' + yoyText));

            if (data.peak_duration) {
                hud.appendChild(el('div', 'hudSub',
                    'Most common in marriages lasting ' + data.peak_duration.marriage_duration +
                    ' (' + data.peak_duration.share_of_reason_pct + '% of cases citing this reason).'));
            }
            hud.classList.add('ready');
        } catch (e) {
            // stay hidden if API is offline
        }
    }

    // Fills the empty bottom strip of the Room Selection hub with live MySQL + MongoDB stats
    async function loadHubPulse() {
        let bar = document.getElementById('hubPulseBar');
        if (!bar) {
            bar = el('div', 'hubPulseBar');
            bar.id = 'hubPulseBar';
            document.body.appendChild(bar);
        }
        try {
            const data = await api('GET', '/stats/hub');
            const items = [];
            if (data.sql && data.sql.top_reasons && data.sql.top_reasons[0]) {
                const top = data.sql.top_reasons[0];
                items.push('SG ' + data.sql.year + ' #1 divorce reason: ' + top.reason + ' (' + top.percent + '%)');
            }
            if (data.sql && data.sql.peak_age) {
                items.push('Peak divorce age group: ' + data.sql.peak_age.age_group + ' (' + data.sql.peak_age.percent + '%)');
            }
            if (data.sql && data.sql.biggest_rise) {
                items.push('Fastest rise: ' + data.sql.biggest_rise.reason + ' (+' + data.sql.biggest_rise.delta.toLocaleString() + ' YoY)');
            }
            if (data.community && data.community.total_sessions > 0) {
                items.push('Community runs logged in MongoDB: ' + data.community.total_sessions);
            }
            if (!items.length) return;

            let idx = 0;
            bar.textContent = items[0];
            if (bar.dataset.timer) clearInterval(Number(bar.dataset.timer));
            bar.dataset.timer = String(setInterval(function () {
                idx = (idx + 1) % items.length;
                bar.textContent = items[idx];
            }, 4500));
        } catch (e) {
            bar.style.display = 'none';
        }
    }

    // Each room file sets its own "maxTension" (the highest total a player can reach)
    function maxTension() {
        return (game.room && game.room.maxTension) || MAX_TENSION_POINTS;
    }

    function setTension(points) {
        game.tensionPoints = points;
        const t = Math.min(1, points / maxTension());
        if (story) {
            story.root.style.setProperty('--tension', t.toFixed(2));
            story.root.classList.toggle('shaking', t >= 0.8 && !reduceMotion);
        }
        setTensionAudio(t);
    }

    function showMessage(message, actions) {
        story.body.replaceChildren();
        story.body.appendChild(el('p', 'storyText', message));
        const list = el('div', 'storyChoices show');
        actions.forEach(function (a) {
            const b = el('button', 'storyChoice', a.label);
            b.type = 'button';
            b.addEventListener('click', a.run);
            list.appendChild(b);
        });
        story.body.appendChild(list);
    }

    function backToRooms() {
        closeStory();
        restoreCardAudio();
        setCardsVisible(true);
    }

    // ---------- account: signup, login, new start, save/load, continue ----------

    function inputField(label, type, name, autocomplete) {
        const row = el('label', 'authLabel', label);
        const input = el('input', 'authInput');
        input.type = type;
        input.name = name;
        input.autocomplete = autocomplete;
        input.required = true;
        input.maxLength = type === 'password' ? 72 : 20;
        row.appendChild(input);
        return { row: row, input: input };
    }

    function overlayCard(titleText) {
        const wrap = el('div', 'warn');
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'warnTitle');
        const card = el('div', 'warnCard');
        const title = el('h1', 'warnTitle', titleText);
        title.id = 'warnTitle';
        card.appendChild(title);
        wrap.appendChild(card);
        document.body.appendChild(wrap);
        return { wrap: wrap, card: card };
    }

    function showAuth() {
        const ui = overlayCard('Your story');
        const card = ui.card;
        card.appendChild(el('p', null, 'Log in to continue your story, or sign up to begin a new one. Every choice you make is saved to your account.'));

        let mode = 'login';
        const tabs = el('div', 'authTabs');
        const tabLogin = el('button', 'storyChoice authTab', 'Log in');
        const tabSignup = el('button', 'storyChoice authTab', 'Sign up');
        [tabLogin, tabSignup].forEach(function (t) { t.type = 'button'; tabs.appendChild(t); });
        card.appendChild(tabs);

        const form = el('form', 'authForm');
        const user = inputField('Username', 'text', 'username', 'username');
        const pass = inputField('Password', 'password', 'password', 'current-password');
        const hint = el('p', 'authHint', '');
        const error = el('p', 'authError', '');
        error.setAttribute('role', 'alert');
        const submit = el('button', 'storyChoice authSubmit', 'Log in');
        submit.type = 'submit';
        form.append(user.row, pass.row, hint, error, submit);
        card.appendChild(form);

        function setMode(m) {
            mode = m;
            tabLogin.setAttribute('aria-pressed', String(m === 'login'));
            tabSignup.setAttribute('aria-pressed', String(m === 'signup'));
            submit.textContent = m === 'login' ? 'Log in' : 'Create account';
            pass.input.autocomplete = m === 'login' ? 'current-password' : 'new-password';
            hint.textContent = m === 'signup' ? 'Username: 3 to 20 letters, numbers or underscores. Password: at least 6 characters.' : '';
            error.textContent = '';
        }
        tabLogin.addEventListener('click', function () { setMode('login'); });
        tabSignup.addEventListener('click', function () { setMode('signup'); });
        setMode('login');

        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            error.textContent = '';
            submit.disabled = true;
            try {
                const res = await api('POST', mode === 'login' ? '/auth/login' : '/auth/signup', {
                    username: user.input.value,
                    password: pass.input.value
                });
                ui.wrap.remove();
                onAuthed(res);
            } catch (e) {
                error.textContent = e.message === 'Failed to fetch'
                    ? 'The game server is not reachable. Check that it is running.'
                    : e.message;
                submit.disabled = false;
            }
        });
        user.input.focus({ preventScroll: true });
    }

    function onAuthed(res, replayMode) {
        game.user = { username: res.username, age_group: res.age_group };
        game.profile = res.age_group ? { age_group: res.age_group } : null;
        game.sessionID = null;
        game.saveName = null;
        addUserBar();

        if (replayMode && replayMode !== 'on' && replayMode !== 'off') {
            // Loaded a specific save file from mid-game
            api('GET', '/sessions/' + encodeURIComponent(replayMode))
                .then(function (data) {
                    game.sessionID = data.save.sessionID;
                    game.saveName = data.save.save_name;
                    restoreProgress(data.save);
                    showToast('Loaded "' + data.save.save_name + '"');
                })
                .catch(function (e) {
                    showToast('Could not load save: ' + e.message);
                });
            return;
        }

        if (replayMode) {
            if (!res.age_group) showPersonalStart();
        } else if (res.resume) {
            showWelcomeBack(res.resume, res.saves || []);
        } else if (res.finished) {
            showFinished(res.finished, res.saves || []);
        } else if (!res.age_group) {
            showPersonalStart();
        }
    }

    function formatSaveMeta(s) {
        const where = s.completed
            ? 'Completed'
            : (s.current_room ? 'Room ' + s.current_room : 'Room Hub');
        const dt = s.updated_at ? new Date(s.updated_at).toLocaleString([], {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        }) : '';
        return where + ' · ' + s.choices_made + ' choice' + (s.choices_made === 1 ? '' : 's') +
            ' · ' + s.rooms_done.length + '/' + rooms.length + ' rooms' + (dt ? ' · ' + dt : '');
    }

    function showWelcomeBack(resume, saves) {
        const ui = overlayCard('Welcome back, ' + game.user.username);
        ui.card.appendChild(el('p', null,
            'Last played "' + (resume.save_name || 'Autosave') + '" (' + formatSaveMeta(resume) + ').'));
        const actions = el('div', 'warnActions');
        const cont = el('button', 'storyChoice', 'Continue');
        const loadBtn = el('button', 'storyChoice', 'Load Save File' + (saves.length ? ' (' + saves.length + ')' : ''));
        const fresh = el('button', 'storyChoice', 'Start New Game');
        const error = el('p', 'authError', '');
        [cont, loadBtn, fresh].forEach(function (b) { b.type = 'button'; actions.appendChild(b); });
        actions.appendChild(error);
        ui.card.appendChild(actions);

        cont.addEventListener('click', function () {
            ui.wrap.remove();
            game.sessionID = resume.sessionID;
            game.saveName = resume.save_name;
            restoreProgress(resume);
        });
        loadBtn.addEventListener('click', function () {
            ui.wrap.remove();
            openLoadModal(function () { showWelcomeBack(resume, saves); });
        });
        fresh.addEventListener('click', async function () {
            fresh.disabled = cont.disabled = loadBtn.disabled = true;
            try {
                const label = 'Save ' + (saves.length + 1);
                const created = await api('POST', '/game/new', { save_name: label });
                game.sessionID = created.sessionID;
                game.saveName = label;
                ui.wrap.remove();
            } catch (e) {
                error.textContent = e.message;
                fresh.disabled = cont.disabled = loadBtn.disabled = false;
            }
        });
        cont.focus({ preventScroll: true });
    }

    function showFinished(f, saves) {
        const ui = overlayCard('Welcome back, ' + game.user.username);
        ui.card.appendChild(el('p', null,
            'You finished your last story (' + f.choices_made + ' choice' + (f.choices_made === 1 ? '' : 's') +
            '). View your last report, load an earlier save file, or start a new game.'));
        const actions = el('div', 'warnActions');
        const view = el('button', 'storyChoice', 'View my last report');
        const loadBtn = el('button', 'storyChoice', 'Load Save File' + (saves.length ? ' (' + saves.length + ')' : ''));
        const fresh = el('button', 'storyChoice', 'Start a new game');
        const error = el('p', 'authError', '');
        [view, loadBtn, fresh].forEach(function (b) { b.type = 'button'; actions.appendChild(b); });
        actions.appendChild(error);
        ui.card.appendChild(actions);

        view.addEventListener('click', async function () {
            view.disabled = fresh.disabled = loadBtn.disabled = true;
            try {
                const res = await api('GET', '/sessions/' + f.sessionID + '/outcome');
                ui.wrap.remove();
                skipIntro();
                openStory();
                story.root.classList.add('dashMode');
                renderDashboard(res.outcome);
                if (typeof stopTension === 'function') stopTension();
            } catch (e) {
                error.textContent = e.message;
                view.disabled = fresh.disabled = loadBtn.disabled = false;
            }
        });
        loadBtn.addEventListener('click', function () {
            ui.wrap.remove();
            openLoadModal(function () { showFinished(f, saves); });
        });
        fresh.addEventListener('click', function () {
            ui.wrap.remove();
            game.sessionID = null;
            game.saveName = null;
            if (!game.profile) showPersonalStart();
        });
        view.focus({ preventScroll: true });
    }

    async function openSaveModal() {
        const ui = overlayCard('Save Progress');
        const currentRoom = story ? game.roomId : null;
        const currentScene = story ? game.sceneID : 'hub';
        const locationLabel = currentRoom
            ? 'Room ' + currentRoom + (game.sceneID ? ' (' + game.sceneID + ')' : '')
            : 'Room Selection Hub';

        ui.card.appendChild(el('p', null, 'Current location: ' + locationLabel));

        const nameField = inputField('Save File Name', 'text', 'save_name', 'off');
        nameField.input.maxLength = 40;
        nameField.input.value = game.saveName || ('Save · ' + locationLabel);
        ui.card.appendChild(nameField.row);

        const error = el('p', 'authError', '');
        const actions = el('div', 'warnActions');
        const overwriteBtn = el('button', 'storyChoice', 'Save (Overwrite Current)');
        const newSlotBtn = el('button', 'storyChoice', 'Save as New File');
        const cancelBtn = el('button', 'storyChoice', 'Cancel');
        [overwriteBtn, newSlotBtn, cancelBtn].forEach(function (b) { b.type = 'button'; actions.appendChild(b); });
        ui.card.append(error, actions);

        async function doSave(asNew) {
            overwriteBtn.disabled = newSlotBtn.disabled = cancelBtn.disabled = true;
            error.textContent = '';
            try {
                await ensureSession();
                const res = await api('POST', '/sessions/' + game.sessionID + '/save', {
                    save_name: nameField.input.value,
                    current_room: currentRoom,
                    current_scene: currentScene,
                    tension_points: game.tensionPoints,
                    as_new: asNew
                });
                game.sessionID = res.save.sessionID;
                game.saveName = res.save.save_name;
                ui.wrap.remove();
                showToast('Saved "' + game.saveName + '"');
            } catch (e) {
                error.textContent = e.message;
                overwriteBtn.disabled = newSlotBtn.disabled = cancelBtn.disabled = false;
            }
        }

        overwriteBtn.addEventListener('click', function () { doSave(false); });
        newSlotBtn.addEventListener('click', function () { doSave(true); });
        cancelBtn.addEventListener('click', function () { ui.wrap.remove(); });
        nameField.input.focus({ preventScroll: true });
    }

const MAX_SAVE_SLOTS = 5;

    function buildSlotCard(slotNum, saveData, options) {
        const isEmpty = !saveData;
        const isCurrent = saveData && saveData.sessionID === game.sessionID;
        const card = el('button', 'saveSlotCard' + (isEmpty ? ' empty' : '') + (isCurrent ? ' active' : ''));
        card.type = 'button';

        const header = el('div', 'saveSlotHeader');
        const left = el('div', 'saveSlotTitleGroup');
        left.appendChild(el('span', 'saveSlotBadge', 'Slot ' + slotNum));
        left.appendChild(el('span', 'saveSlotName', isEmpty ? 'Empty Slot' : (saveData.save_name || 'Save ' + slotNum)));
        if (isCurrent) {
            left.appendChild(el('span', 'saveSlotCurrent', 'Current'));
        }

        const actionText = isEmpty
            ? (options.allowEmpty ? 'Save here →' : 'Empty')
            : options.actionLabel;
        const action = el('span', 'saveSlotAction', actionText);
        header.append(left, action);

        const meta = el('div', 'saveSlotMeta',
            isEmpty
                ? (options.allowEmpty ? 'Click to save your current progress in this slot' : 'No saved data in this slot')
                : formatSaveMeta(saveData)
        );

        card.append(header, meta);

        if (isEmpty && !options.allowEmpty) {
            card.disabled = true;
        } else {
            card.addEventListener('click', function () {
                options.onSelect(slotNum, saveData);
            });
        }
        return card;
    }

    async function openSaveModal() {
        const ui = overlayCard('Save Progress');
        const currentRoom = story ? game.roomId : null;
        const currentScene = story ? game.sceneID : 'hub';
        const locationLabel = currentRoom ? ('Room ' + currentRoom) : 'Room Selection Hub';

        ui.card.appendChild(el('p', null, 'Checkpoint: ' + locationLabel + '. Choose a slot to save or overwrite (max 5 saves):'));

        const list = el('div', 'saveList');
        const status = el('p', 'saveSlotMeta', 'Loading slots...');
        const error = el('p', 'authError', '');
        const actions = el('div', 'warnActions');
        const cancelBtn = el('button', 'storyChoice', 'Cancel');
        cancelBtn.type = 'button';
        cancelBtn.addEventListener('click', function () { ui.wrap.remove(); });
        actions.appendChild(cancelBtn);
        ui.card.append(status, list, error, actions);

        async function saveToSlot(slotNum, existingSave) {
            list.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
            cancelBtn.disabled = true;
            error.textContent = '';

            try {
                await ensureSession();
                const payload = {
                    current_room: currentRoom,
                    current_scene: currentScene,
                    tension_points: game.tensionPoints,
                    slot: slotNum,
                    as_new: !existingSave,
                    target_session_id: existingSave ? existingSave.sessionID : null
                };
                const res = await api('POST', '/sessions/' + game.sessionID + '/save', payload);
                game.sessionID = res.save.sessionID;
                game.saveName = res.save.save_name;
                ui.wrap.remove();
                showToast('Saved to Slot ' + slotNum + ' (' + game.saveName + ')');
            } catch (e) {
                error.textContent = e.message;
                list.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                cancelBtn.disabled = false;
            }
        }

        try {
            const res = await api('GET', '/sessions');
            const saves = res.saves || [];
            const bySlot = {};
            saves.forEach(function (s, idx) {
                const slot = s.slot || (idx + 1);
                if (!bySlot[slot]) bySlot[slot] = s;
            });

            status.remove();
            for (let i = 1; i <= MAX_SAVE_SLOTS; i++) {
                list.appendChild(buildSlotCard(i, bySlot[i] || null, {
                    allowEmpty: true,
                    actionLabel: 'Overwrite →',
                    onSelect: saveToSlot
                }));
            }
        } catch (e) {
            status.textContent = '';
            error.textContent = e.message;
        }
    }

    async function openLoadModal(onCancel) {
        const ui = overlayCard('Load Save File');
        const status = el('p', null, 'Select a save slot to resume from that point:');
        const list = el('div', 'saveList');
        const error = el('p', 'authError', '');
        const actions = el('div', 'warnActions');
        const closeBtn = el('button', 'storyChoice', 'Back');
        closeBtn.type = 'button';
        closeBtn.addEventListener('click', function () {
            ui.wrap.remove();
            if (typeof onCancel === 'function') onCancel();
        });
        actions.appendChild(closeBtn);
        ui.card.append(status, list, error, actions);

        function loadSlot(slotNum, s) {
            if (!s) return;
            if (document.getElementById('roomChoices')) {
                try {
                    sessionStorage.setItem('wvReplay', soundOn ? 'on' : 'off');
                    sessionStorage.setItem('wvLoadSession', s.sessionID);
                } catch (e) { }
                location.reload();
                return;
            }
            ui.wrap.remove();
            game.sessionID = s.sessionID;
            game.saveName = s.save_name;
            restoreProgress(s);
            showToast('Loaded Slot ' + slotNum + ' (' + s.save_name + ')');
        }

        try {
            const res = await api('GET', '/sessions');
            const saves = res.saves || [];
            const bySlot = {};
            saves.forEach(function (s, idx) {
                const slot = s.slot || (idx + 1);
                if (!bySlot[slot]) bySlot[slot] = s;
            });

            if (!saves.length) {
                status.textContent = 'No save files found yet.';
            }

            for (let i = 1; i <= MAX_SAVE_SLOTS; i++) {
                list.appendChild(buildSlotCard(i, bySlot[i] || null, {
                    allowEmpty: false,
                    actionLabel: 'Load →',
                    onSelect: loadSlot
                }));
            }
        } catch (e) {
            status.textContent = '';
            error.textContent = e.message;
        }
    }

    function skipIntro() {
        try {
            canvas.getObjects().forEach(function (o) {
                if (o !== chapelBackground) {
                    o.set({ visible: false, evented: false });
                }
            });
            if (chapelBackground) {
                chapelBackground.scaleX *= 1.1 * 1.35;
                chapelBackground.scaleY *= 1.1 * 1.35;
                chapelBackground.filters = [new fabric.Image.filters.Blur({ blur: 0.25 })];
                chapelBackground.applyFilters();
            }
            canvas.renderAll();
        } catch (e) {
            console.warn('Intro not skipped cleanly:', e.message);
        }
        if (typeof startBreathingHeartbeat === 'function') startBreathingHeartbeat();
    }

    function restoreProgress(r) {
        const done = r.rooms_done || [];
        done.forEach(function (id) { visitedRooms.add(id); unlockRoom(id + 1); });
        skipIntro();
        showRoomChoices();
        if (done.length >= rooms.length) { showEnding(); return; }

        const m = /^r(\d+)_/.exec(r.current_scene || '');
        const room = r.current_room || (m ? Number(m[1]) : null);
        if (room && !visitedRooms.has(room)) {
            unlockRoom(room);
            enterRoom(room, r.current_scene, r.choice_ids, r.tension_points);
        }
    }

    function addUserBar() {
        if (document.getElementById('userBar')) return;
        const bar = el('div', 'userBar');
        bar.id = 'userBar';
        bar.appendChild(el('span', 'userName', game.user.username));

        const saveBtn = el('button', 'userLogout', 'Save');
        saveBtn.type = 'button';
        saveBtn.addEventListener('click', openSaveModal);

        const loadBtn = el('button', 'userLogout', 'Load');
        loadBtn.type = 'button';
        loadBtn.addEventListener('click', function () { openLoadModal(); });

        const out = el('button', 'userLogout', 'Log out');
        out.type = 'button';
        out.addEventListener('click', async function () {
            try { await api('POST', '/auth/logout'); } catch (e) { }
            location.reload();
        });

        bar.append(saveBtn, loadBtn, out);
        document.body.appendChild(bar);
    }

    async function startAccountFlow(modeOverride) {
        try {
            onAuthed(await api('GET', '/auth/me'), modeOverride !== undefined ? modeOverride : replay);
        } catch (e) {
            showAuth();
        }
    }

    // ---------- content warning (shown before the doors) ----------

    function showContentWarning() {
        const wrap = el('div', 'warn');
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'warnTitle');

        const card = el('div', 'warnCard');
        const title = el('h1', 'warnTitle', 'Before you begin');
        title.id = 'warnTitle';
        card.appendChild(title);
        card.appendChild(el('p', null,
            'Wedding Vows is an interactive story about a marriage under strain. It includes infidelity, ' +
            'shouting, and a scene of domestic violence (not shown graphically). The sound includes a ' +
            'heartbeat and heavy breathing.'));
        card.appendChild(el('p', null,
            'You can stop at any time by closing the page. If you or someone you know needs help now, ' +
            'call 999 or the National Anti-Violence and Sexual Harassment Helpline on 1800-777-0000.'));

        const actions = el('div', 'warnActions');
        [['Start with sound', true], ['Start without sound', false]].forEach(function (opt) {
            const b = el('button', 'storyChoice', opt[0]);
            b.type = 'button';
            b.addEventListener('click', function () {
                setSound(opt[1]);
                wrap.remove();
                startAccountFlow();
            });
            actions.appendChild(b);
        });
        card.appendChild(actions);
        wrap.appendChild(card);
        document.body.appendChild(wrap);
        actions.firstChild.focus({ preventScroll: true });
    }

    // ---------- personal start (pick an age group, see how it compares) ----------

    function ageDirection(steps, medianGroup) {
        if (steps === 0) return 'The typical (median) divorcing person is in your age group.';
        const n = Math.abs(steps);
        return 'The typical (median) divorcing person is in the ' + medianGroup + ' group, ' +
            n + (n === 1 ? ' age group ' : ' age groups ') + (steps < 0 ? 'older' : 'younger') + ' than yours.';
    }

    function renderComparison(card, d) {
        card.replaceChildren();
        const title = el('h1', 'warnTitle', 'You, and the figures');
        title.id = 'warnTitle';
        card.appendChild(title);

        const a = d.age;
        const women = a.by_gender.find(function (g) { return g.gender === 'Female'; });
        const men = a.by_gender.find(function (g) { return g.gender === 'Male'; });

        card.appendChild(el('p', null,
            'In ' + d.year + ', ' + a.count.toLocaleString() + ' people aged ' + d.age_group + ' divorced in Singapore. ' +
            'That is ' + percent(a.percent) + ' of everyone who divorced, the number ' + a.rank + ' age group out of ' +
            a.of_groups + (women && men ? '. ' + percent(women.percent) + ' were women and ' + percent(men.percent) + ' were men.' : '.')));
        card.appendChild(el('p', null, ageDirection(a.steps_from_median, a.median_group)));

        // Mini bars: who divorced, by age group, with the player's group marked
        const max = Math.max.apply(null, a.groups.map(function (g) { return g.percent; }).concat([1]));
        const bars = el('div', 'ageBars');
        a.groups.forEach(function (g) {
            const row = el('div', 'bar' + (g.age_group === d.age_group ? ' mine' : ''));
            row.appendChild(el('span', 'barLabel', g.age_group));
            const track = el('span', 'barTrack');
            const fill = el('span', 'barFill');
            fill.style.width = (g.percent / max * 100) + '%';
            track.appendChild(fill);
            row.appendChild(track);
            row.appendChild(el('span', 'barValue', percent(g.percent)));
            bars.appendChild(row);
        });
        card.appendChild(bars);

        if (d.duration && d.duration.median_label) {
            card.appendChild(el('p', null,
                'How long had these marriages lasted? The typical (median) one that ended in divorce was in the ' +
                d.duration.median_label.toLowerCase() + ' group' +
                (d.duration.under5_percent !== null ? ', and ' + percent(d.duration.under5_percent) + ' ended within five years.' : '.')));
        }
        card.appendChild(el('p', 'dashSource',
            'These count the people who divorced. They are not the chance that any one person will. ' +
            'Source: Singapore Department of Statistics (SingStat).'));

        const go = el('button', 'storyChoice endingBtn', 'Open the doors');
        go.type = 'button';
        go.addEventListener('click', function () { card.parentNode.remove(); });
        card.appendChild(go);
        go.focus({ preventScroll: true });
    }

    // Optional. If the API is down this is skipped and the game starts as before.
    async function showPersonalStart() {
        let groups;
        try {
            groups = (await api('GET', '/stats/age-groups')).age_groups;
        } catch (e) {
            console.warn('Personal start skipped:', e.message);
            return;
        }
        if (!groups || !groups.length) return;

        const wrap = el('div', 'warn');
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'warnTitle');
        const card = el('div', 'warnCard');
        const title = el('h1', 'warnTitle', 'Before the doors');
        title.id = 'warnTitle';
        card.appendChild(title);
        card.appendChild(el('p', null,
            'Which age group are you in? You will see how it compares with divorces in Singapore. ' +
            'This is optional. If you pick one, only the age group is saved with your account.'));

        const grid = el('div', 'ageGrid');
        groups.forEach(function (g) {
            const b = el('button', 'storyChoice', g);
            b.type = 'button';
            b.addEventListener('click', async function () {
                grid.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
                game.profile = { age_group: g };
                api('POST', '/auth/profile', { age_group: g }).catch(function (e) {
                    console.warn('Age group not saved:', e.message);
                });
                try {
                    renderComparison(card, await api('GET', '/stats/profile?age_group=' + encodeURIComponent(g)));
                } catch (e) {
                    console.warn('Comparison unavailable:', e.message);
                    wrap.remove();   // the profile is still kept, the story just starts
                }
            });
            grid.appendChild(b);
        });
        card.appendChild(grid);

        const skip = el('button', 'storyChoice', 'Skip');
        skip.type = 'button';
        skip.addEventListener('click', function () { wrap.remove(); });
        card.appendChild(skip);

        wrap.appendChild(card);
        document.body.appendChild(wrap);
        grid.firstChild.focus({ preventScroll: true });
    }

    // ---------- entering a room and playing scenes ----------

    async function enterRoom(id, startScene, choiceIDs, savedTension) {
        if (game.busy || visitedRooms.has(id)) return;
        game.busy = true;
        setCardsVisible(false);
        openStory();
        try {
            game.room = await loadRoom(id);
            game.roomId = id;
            let points = 0;
            if (choiceIDs) {
                Object.keys(game.room.scenes).forEach(function (k) {
                    (game.room.scenes[k].choices || []).forEach(function (c) {
                        if (choiceIDs.indexOf(c.id) !== -1) points += c.tension || 0;
                    });
                });
            }
            setTension(typeof savedTension === 'number' ? savedTension : points);
            game.busy = false;
            updateMidgameHud(ROOM_DEFAULT_REASON[id]);
            renderScene(startScene || game.room.start);
        } catch (e) {
            game.busy = false;
            console.error('Could not open room ' + id + ':', e);
            showMessage(
                'This room could not be loaded (' + e.message + '). Check that the rooms folder sits next to index.html, then try again.',
                [
                    { label: 'Try again', run: function () { enterRoom(id, startScene, choiceIDs, savedTension); } },
                    { label: 'Back to the rooms', run: backToRooms }
                ]
            );
        }
    }

    function renderScene(sceneID) {
        const scene = game.room.scenes[sceneID];
        if (!scene) {
            showMessage('This part of the story is missing (' + sceneID + ').',
                [{ label: 'Back to the rooms', run: backToRooms }]);
            return;
        }
        game.sceneID = sceneID;
        stopSfx(400);
        if (scene.ending) {
            renderEnding(scene);
            return;
        }

        story.body.replaceChildren();
        story.body.appendChild(el('p', 'storyRoomName', game.room.title));

        const text = el('p', 'storyText', scene.text);
        text.tabIndex = -1;   // so keyboard and screen reader users land on the new text
        story.body.appendChild(text);

        const list = el('div', 'storyChoices');
        scene.choices.forEach(function (choice) {
            const b = el('button', 'storyChoice', choice.label);
            b.type = 'button';
            b.addEventListener('click', function () { pick(choice, b, list); });
            list.appendChild(b);
        });
        story.body.appendChild(list);

        text.focus({ preventScroll: true });
        (scene.sfx || []).forEach(playSfx);
        if (scene.impact) flashImpact();
        // choices fade in after the player has had a moment with the text
        setTimeout(function () {
            list.classList.add('show');
        }, reduceMotion ? 0 : 700);
    }

    async function pick(choice, button, list) {
        if (game.busy) return;
        game.busy = true;
        list.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        button.classList.add('chosen');

        setTension(Math.max(0, game.tensionPoints + (choice.tension || 0)));
        if (choice.reason) updateMidgameHud(choice.reason);

        // The API decides the next scene. If it cannot be reached, the room file is used.
        let next = choice.next;
        let saved = false;
        try {
            await ensureSession();
            const res = await api('POST', '/choices', {
                sessionID: game.sessionID,
                choiceID: choice.id,
                sceneID: game.sceneID
            });
            next = res.next_scene || next;
            saved = true;
        } catch (e) {
            game.offline = true;
            console.warn('API unavailable, continuing from the room file:', e.message);
        }

        // "What other players chose": only after the choice is saved, so the player's own pick
        // is already counted. A scene can opt out with "stats": false in its room file.
        const scene = game.room.scenes[game.sceneID];
        const stats = saved && scene.stats !== false ? await fetchChoiceStats(game.sceneID) : null;
        const line = stats ? describeStats(scene, stats, choice.id) : null;

        if (line) {
            await showStatsAndWait(list, line);
        } else {
            await wait(450);
        }
        game.busy = false;
        renderScene(next);
    }

    // ---------- what other players chose ----------

    // Never blocks the story: any failure just means no statistics are shown.
    async function fetchChoiceStats(sceneID) {
        try {
            const data = await api('GET', '/stats/choices?sceneID=' + encodeURIComponent(sceneID));
            return data.enough ? data : null;   // too few players for a percentage to mean anything
        } catch (e) {
            console.warn('Choice statistics unavailable:', e.message);
            return null;
        }
    }

    // "62% matched his volume, 31% walked away"
    // Uses the optional "recap" of a choice (past tense, in the room file); falls back to its label.
    function describeStats(scene, stats, pickedID) {
        const parts = scene.choices
            .map(function (c) {
                const s = stats.choices[c.id];
                return { id: c.id, percent: s ? s.percent : 0, text: c.recap || ('chose \u201c' + c.label + '\u201d') };
            })
            .filter(function (r) { return r.percent > 0; })
            .sort(function (a, b) { return b.percent - a.percent; });
        if (parts.length === 0) return null;

        const top = parts.slice(0, 2);
        const sentence = top.map(function (r) { return r.percent + '% ' + r.text; });
        const mine = parts.find(function (r) { return r.id === pickedID; });
        if (mine && !top.includes(mine)) {
            sentence.push(mine.percent + '% chose what you did');
        }
        return sentence.join(', ');
    }

    // Shows the line under the choices and resolves when the player presses Continue.
    function showStatsAndWait(list, line) {
        return new Promise(function (resolve) {
            const box = el('div', 'storyStats');
            box.setAttribute('role', 'status');
            box.appendChild(el('p', 'storyStatsLabel', 'What other players chose'));
            box.appendChild(el('p', 'storyStatsLine', line));
            const go = el('button', 'storyChoice storyContinue', 'Continue');
            go.type = 'button';
            go.addEventListener('click', resolve, { once: true });
            box.appendChild(go);
            list.after(box);
            go.focus({ preventScroll: true });
        });
    }

    function renderEnding(scene) {
        const e = scene.ending;

        // hopeful endings calm the screen, dark ones peak it
        if (e.tone === 'hopeful') setTension(0);
        if (e.tone === 'dark') setTension(maxTension());

        story.body.replaceChildren();
        const wrap = el('div', 'storyEnding tone-' + e.tone);
        const title = el('h2', 'endingTitle', e.title);
        title.tabIndex = -1;
        wrap.appendChild(title);
        wrap.appendChild(el('p', 'storyText', scene.text));
        if (e.showSupport) wrap.appendChild(buildSupport());

        const lastRoom = visitedRooms.size === rooms.length - 1;
        const btn = el('button', 'storyChoice endingBtn', lastRoom ? 'See the national figures' : 'Back to the rooms');
        btn.type = 'button';
        btn.addEventListener('click', finishRoom);
        wrap.appendChild(btn);

        story.body.appendChild(wrap);
        title.focus({ preventScroll: true });
    }

    function buildSupport() {
        const box = el('section', 'support');
        box.appendChild(el('h3', 'supportTitle', 'If any of this feels close to home'));
        box.appendChild(el('p', 'supportNote', 'You can reach someone now.'));
        const ul = el('ul', 'supportList');
        SUPPORT.forEach(function (s) {
            const li = el('li');
            li.appendChild(el('span', 'supportName', s.name + (s.detail ? ' (' + s.detail + ')' : '')));
            const a = el('a', 'supportNumber', s.label);
            a.href = 'tel:' + s.tel;
            li.appendChild(a);
            ul.appendChild(li);
        });
        box.appendChild(ul);
        return box;
    }

    async function showHalfwayCheckpoint() {
        openStory();
        story.root.classList.add('dashMode');
        story.body.appendChild(el('p', 'storyText', 'Loading your halfway snapshot...'));

        try {
            await ensureSession();
            const qs = '?reason=' + encodeURIComponent('Adultery & Infidelity') +
                       '&sessionID=' + encodeURIComponent(game.sessionID);
            const data = await api('GET', '/stats/midgame' + qs);

            story.body.replaceChildren();
            const wrap = el('div', 'dash');
            wrap.appendChild(el('p', 'hudKicker', 'Halfway Checkpoint · Rooms 1–2 Complete'));
            wrap.appendChild(el('h2', 'dashTitle', 'Where your marriage stands so far'));

            const soFar = data.session_so_far || {};
            const scores = Object.entries(soFar.scores || {});
            if (scores.length) {
                wrap.appendChild(el('p', 'dashLead',
                    'Across ' + soFar.choices_made + ' choices so far, your path has leaned toward ' +
                    soFar.top_reason + '.'));
            } else {
                wrap.appendChild(el('p', 'dashLead',
                    'Across ' + (soFar.choices_made || 0) + ' choices so far, you have kept communication open — let’s see if it holds when trust is tested.'));
            }

            const comm = data.community || {};
            const secretPct = comm.secret_percent || 0;
            const billsPct  = comm.bills_percent || 0;
            const peakAge   = data.sql_facts && data.sql_facts.peak_age;

            const grid = el('div', 'halfwayGrid');

            const c1 = el('div', 'halfwayStat');
            c1.appendChild(el('div', 'halfwayNum', secretPct + '%'));
            c1.appendChild(el('div', 'halfwayLbl', 'of players hid messages in Room 2 (MongoDB)'));

            const c2 = el('div', 'halfwayStat');
            c2.appendChild(el('div', 'halfwayNum', billsPct + '%'));
            c2.appendChild(el('div', 'halfwayLbl', 'avoided money talk in Room 1 (MongoDB)'));

            const c3 = el('div', 'halfwayStat');
            c3.appendChild(el('div', 'halfwayNum', peakAge ? peakAge.age_group : '35-39'));
            c3.appendChild(el('div', 'halfwayLbl',
                'peak divorce age band in Singapore ' + (data.sql_facts ? data.sql_facts.year : '') +
                (peakAge ? ' (' + peakAge.percent + '%, MySQL)' : '')));

            grid.append(c1, c2, c3);
            wrap.appendChild(grid);

            if (soFar.top_reason) {
                const sp = (await api('GET', '/stats/midgame?reason=' + encodeURIComponent(soFar.top_reason))).spotlight;
                if (sp && sp.summary) {
                    wrap.appendChild(el('p', 'dashText',
                        'In Singapore (' + sp.year + '), "' + sp.reason + '" was cited in ' +
                        sp.summary.count.toLocaleString() + ' divorces (' + sp.summary.share_pct +
                        '%, Rank #' + sp.summary.rank + ')' +
                        (sp.peak_duration ? ' — peaking in marriages of ' + sp.peak_duration.marriage_duration + '.' : '.')));
                }
            }

            const cont = el('button', 'storyChoice endingBtn', 'Continue to Room 3');
            cont.type = 'button';
            cont.addEventListener('click', function () {
                closeStory();
                restoreCardAudio();
                setCardsVisible(true);
            });
            wrap.appendChild(cont);
            story.body.appendChild(wrap);
        } catch (e) {
            closeStory();
            restoreCardAudio();
            setCardsVisible(true);
        }
    }

    function finishRoom() {
        const id = game.roomId;
        if (game.sessionID) {
            api('POST', '/sessions/' + game.sessionID + '/room-done', { room: id }).catch(function (e) {
                console.warn('Room progress not saved:', e.message);
            });
        }
        closeStory();
        restoreCardAudio();
        unlockRoom(id + 1);
        completeRoom(id);   // calls showEnding() once all four rooms are done
        if (id === 2 && visitedRooms.size < rooms.length) {
            showHalfwayCheckpoint();
            return;
        }
        if (visitedRooms.size < rooms.length) setCardsVisible(true);
    }

    function playAgain() {
        try { sessionStorage.setItem('wvReplay', soundOn ? 'on' : 'off'); } catch (e) { }
        location.reload();
    }

   // ---------- final dashboard (4-slide carousel + data visuals) ----------

    let dashKeyHandler = null;

    async function showEnding() {
        setCardsVisible(false);
        if (typeof stopTension === 'function') stopTension();
        openStory();
        story.root.classList.add('dashMode');
        story.body.appendChild(el('p', 'storyText', 'Loading the national figures...'));

        try {
            await ensureSession();
            const res = await api('POST', '/sessions/' + game.sessionID + '/complete');
            renderDashboard(res.outcome);
        } catch (e) {
            console.warn('Could not load the outcome:', e.message);
            showMessage(
                'The national figures could not be loaded. Check that the API is running, then try again.',
                [
                    { label: 'Try again', run: showEnding },
                    { label: 'Play again', run: playAgain }
                ]
            );
        }
    }

    function percent(n) {
        return Number(n).toFixed(1) + '%';
    }

   // Clean, editorial stat strip inside each escalation rung (preserves all figures)
    function buildRungVisuals(o, r) {
        const strip = el('div', 'rungStats');

        // 1. National share & rank
        if (r.national) {
            const col = el('div', 'rungStatCol');
            col.appendChild(el('div', 'rungStatLbl', 'SG ' + o.year + ' Share'));
            col.appendChild(el('div', 'rungStatBig', percent(r.national.percent)));
            col.appendChild(el('div', 'rungStatSub',
                r.national.count.toLocaleString() + ' cases · Rank #' + r.national.rank));
            strip.appendChild(col);
        }

        // 2. Peak marriage duration
        if (r.peak_duration) {
            const col = el('div', 'rungStatCol');
            col.appendChild(el('div', 'rungStatLbl', 'Most Common Duration'));
            col.appendChild(el('div', 'rungStatBig', r.peak_duration.label));
            col.appendChild(el('div', 'rungStatSub',
                percent(r.peak_duration.share) + ' of these divorces'));
            strip.appendChild(col);
        }

        // 3. Multi-year trend (from_year vs to_year)
        if (r.trend && r.trend.from_year !== r.trend.to_year) {
            const col = el('div', 'rungStatCol');
            const diff = r.trend.to_count - r.trend.from_count;
            const deltaText = diff > 0
                ? '▲ +' + diff.toLocaleString() + ' since ' + r.trend.from_year
                : (diff < 0
                    ? '▼ ' + diff.toLocaleString() + ' since ' + r.trend.from_year
                    : 'Unchanged since ' + r.trend.from_year);

            col.appendChild(el('div', 'rungStatLbl', r.trend.from_year + ' → ' + r.trend.to_year + ' Cases'));
            col.appendChild(el('div', 'rungStatBig',
                r.trend.from_count.toLocaleString() + ' → ' + r.trend.to_count.toLocaleString()));
            col.appendChild(el('div', 'rungStatSub ' + (diff > 0 ? 'up' : (diff < 0 ? 'down' : '')), deltaText));
            strip.appendChild(col);
        }

        return strip;
    }

    function buildLadder(o) {
        const section = el('section', 'ladder');
        const reached = o.ladder.filter(function (r) { return r.lit; });
        const total = o.ladder.length;

        // 4-step graduated escalation meter
        const meterWrap = el('div', 'ladderMeterWrap');
        const meterHead = el('div', 'ladderMeterHead');
        meterHead.appendChild(el('span', 'dashHeadInline', 'Warning signs: the escalation ladder'));
        meterHead.appendChild(el('span', 'ladderMeterScore', reached.length + ' of ' + total + ' rungs reached'));
        meterWrap.appendChild(meterHead);

        const meter = el('div', 'ladderMeter');
        o.ladder.forEach(function (r, idx) {
            const step = Math.min(4, idx + 1);
            const seg = el('div', 'ladderSeg step-' + step + (r.lit ? ' lit' : ''));
            seg.title = r.title + (r.lit ? ' (Reached)' : ' (Not reached)');
            meter.appendChild(seg);
        });
        meterWrap.appendChild(meter);
        section.appendChild(meterWrap);

        section.appendChild(el('p', 'dashText',
            'Arguments rarely jump to the top. They climb. Beside each rung is the Singapore divorce reason that comes closest to it.'));

        // Highest rung on top (step-4 down to step-1)
        const list = el('ol', 'ladderList');
        o.ladder.slice().reverse().forEach(function (r, revIdx) {
            const step = Math.max(1, Math.min(4, total - revIdx));
            const li = el('li', 'rung step-' + step + (r.lit ? ' lit' : ''));

            const head = el('div', 'rungHead');
            const titleGroup = el('div', 'rungTitleGroup');
            titleGroup.appendChild(el('span', 'rungStepNum', '0' + step));
            titleGroup.appendChild(el('span', 'rungTitle', r.title));
            head.appendChild(titleGroup);
            head.appendChild(el('span', 'rungState', r.lit ? 'Reached' : 'Not reached'));
            li.appendChild(head);

            li.appendChild(el('p', 'rungBlurb', r.blurb));

            const reasonRow = el('div', 'rungReasonRow');
            reasonRow.appendChild(el('span', 'rungReasonTag', 'Closest divorce reason'));
            reasonRow.appendChild(el('span', 'rungReasonName', r.reason));
            li.appendChild(reasonRow);

            li.appendChild(buildRungVisuals(o, r));
            list.appendChild(li);
        });
        section.appendChild(list);

        section.appendChild(el('p', 'dashSource',
            'Reasons are what couples recorded when they divorced. Matching a behaviour to a reason is a guide, not a diagnosis.'));

        if (o.ladder[o.ladder.length - 1].lit) section.appendChild(buildSupport());
        return section;
    }

    // Slide 2: Visual bar breakdown of player's theme points + KPI highlight cards
    function buildThemesSlide(o) {
        const box = el('section', 'dashSlideSection');
        const themes = Object.entries(o.scores || {});

        box.appendChild(el('h3', 'dashHead', 'Themes your story touched'));

        if (themes.length === 0) {
            box.appendChild(el('p', 'dashText', 'Your choices stayed mostly neutral, so no single theme stood out.'));
            return box;
        }

        const totalPts = themes.reduce(function (sum, t) { return sum + Number(t[1]); }, 0) || 1;
        const maxPts = Math.max.apply(null, themes.map(function (t) { return Number(t[1]); }).concat([1]));

        // Horizontal bar graph of player's own choice points
        const chart = el('div', 'themeChart');
        themes.forEach(function (t, idx) {
            const pts = Number(t[1]);
            const sharePct = Math.round((pts / totalPts) * 100);
            const isTop = idx === 0;

            const row = el('div', 'bar' + (isTop ? ' mine' : ''));
            row.appendChild(el('span', 'barLabel', t[0]));
            const track = el('span', 'barTrack');
            const fill = el('span', 'barFill');
            fill.style.width = (pts / maxPts * 100) + '%';
            track.appendChild(fill);
            row.appendChild(track);
            row.appendChild(el('span', 'barValueWide', pts + (pts === 1 ? ' pt' : ' pts') + ' (' + sharePct + '%)'));
            chart.appendChild(row);
        });
        box.appendChild(chart);

        // KPI cards for the player's primary theme against Singapore national stats
        if (o.national_match) {
            const m = o.national_match;
            const kpiGrid = el('div', 'kpiGrid');

            const kpis = [
                { num: m.reason, lbl: 'Your primary theme', accent: true },
                { num: percent(m.percent), lbl: 'Of SG divorces in ' + o.year },
                { num: m.count.toLocaleString(), lbl: 'Recorded cases in ' + o.year },
                { num: '#' + m.rank, lbl: 'National rank in Singapore' }
            ];

            kpis.forEach(function (k) {
                const card = el('div', 'kpiCard' + (k.accent ? ' accent' : ''));
                card.appendChild(el('div', 'kpiNum' + (k.accent ? ' kpiText' : ''), k.num));
                card.appendChild(el('div', 'kpiLbl', k.lbl));
                kpiGrid.appendChild(card);
            });
            box.appendChild(kpiGrid);

            box.appendChild(el('p', 'dashText',
                'Your story touched most on ' + m.reason.toLowerCase() + '. In ' + o.year + ', ' + percent(m.percent) +
                ' of divorces in Singapore cited it (' + m.count.toLocaleString() + ' divorces), the number ' +
                m.rank + ' reason.'));
        }

        return box;
    }

    // Slide 3: National horizontal bars + 100% stacked composition strip
    function buildNationalSlide(o) {
        const box = el('section', 'dashSlideSection');
        const rows = (o.national_top3 || []).slice();
        if (o.national_match && !rows.some(function (r) { return r.reason === o.national_match.reason; })) {
            rows.push(o.national_match);
        }

        box.appendChild(el('h3', 'dashHead', 'Most common reasons in ' + o.year));

        // 100% stacked share bar showing how much of all SG divorces these reasons make up
        const palette = ['#e8c27a', '#d98a94', '#c9877b', '#e0505a'];
        const stripWrap = el('div', 'stackWrap');
        const strip = el('div', 'stackBar');
        const legend = el('div', 'stackLegend');
        let shownPct = 0;

        rows.forEach(function (r, idx) {
            const pct = Number(r.percent);
            shownPct += pct;
            const isMine = o.main_reason === r.reason;
            const color = isMine ? '#e0505a' : palette[idx % palette.length];

            const seg = el('div', 'stackSeg');
            seg.style.width = Math.min(100, pct) + '%';
            seg.style.background = color;
            seg.title = r.reason + ': ' + percent(pct);
            strip.appendChild(seg);

            const item = el('span', 'stackLegendItem');
            const dot = el('span', 'cmpKey');
            dot.style.background = color;
            item.append(dot, document.createTextNode(r.reason + ' (' + percent(pct) + ')'));
            legend.appendChild(item);
        });

        const otherPct = Math.max(0, 100 - shownPct);
        if (otherPct > 0.1) {
            const otherSeg = el('div', 'stackSeg other');
            otherSeg.style.width = otherPct + '%';
            otherSeg.title = 'All other reasons: ' + percent(otherPct);
            strip.appendChild(otherSeg);

            const item = el('span', 'stackLegendItem muted');
            const dot = el('span', 'cmpKey other');
            item.append(dot, document.createTextNode('Other reasons (' + percent(otherPct) + ')'));
            legend.appendChild(item);
        }

        stripWrap.append(
            el('div', 'stackCaption', 'Share of all divorces in Singapore (' + o.year + ')'),
            strip,
            legend
        );
        box.appendChild(stripWrap);

        // Detailed horizontal bars
        const max = Math.max.apply(null, rows.map(function (r) { return Number(r.percent); }).concat([1]));
        rows.forEach(function (r) {
            const isMine = o.main_reason === r.reason;
            const row = el('div', 'bar' + (isMine ? ' mine' : ''));
            const lbl = el('span', 'barLabel', r.reason);
            if (isMine) lbl.appendChild(el('span', 'mineTag', 'Your main theme'));
            row.appendChild(lbl);

            const track = el('span', 'barTrack');
            const fill = el('span', 'barFill');
            fill.style.width = (Number(r.percent) / max * 100) + '%';
            track.appendChild(fill);
            row.appendChild(track);
            row.appendChild(el('span', 'barValue', percent(r.percent)));
            box.appendChild(row);
        });

        box.appendChild(el('p', 'dashSource', 'Source: Singapore Department of Statistics (SingStat).'));
        return box;
    }

    // Comparison age chart (Slide 4 when age group was selected)
    function buildAgeChart(ac) {
        const box = el('div', 'cmp');
        const legend = el('div', 'cmpLegend');
        [['cmpMine', ac.age_group], ['cmpAll', 'All players']].forEach(function (k) {
            const item = el('span', null);
            item.appendChild(el('span', 'cmpKey ' + k[0]));
            item.appendChild(document.createTextNode(k[1]));
            legend.appendChild(item);
        });
        box.appendChild(legend);

        const max = Math.max.apply(null, ac.themes.map(function (t) {
            return Math.max(Number(t.group_percent), Number(t.all_percent));
        }).concat([1]));
        const chart = el('div', 'cmpChart');
        chart.setAttribute('role', 'img');
        chart.setAttribute('aria-label', ac.themes.map(function (t) {
            return t.reason + ': ' + ac.age_group + ' ' + t.group_percent + ' percent, all players ' + t.all_percent + ' percent';
        }).join('. '));
        const labels = el('div', 'cmpLabels');
        const fills = [];

        ac.themes.forEach(function (t) {
            const group = el('div', 'cmpGroup');
            [[t.group_percent, 'cmpMine'], [t.all_percent, 'cmpAll']].forEach(function (v) {
                const col = el('div', 'cmpCol');
                col.appendChild(el('div', 'cmpVal', Math.round(v[0]) + '%'));
                const fill = el('div', 'cmpFill ' + v[1]);
                col.appendChild(fill);
                group.appendChild(col);
                fills.push({ fill: fill, h: Number(v[0]) / max * 85 });
            });
            chart.appendChild(group);
            labels.appendChild(el('div', 'cmpLabel', t.reason));
        });
        box.append(chart, labels);

        setTimeout(function () {
            fills.forEach(function (f) { f.fill.style.height = f.h + '%'; });
        }, 120);
        return box;
    }

    function buildAgeSlide(ac) {
        const box = el('section', 'dashSlideSection');
        box.appendChild(el('h3', 'dashHead', 'You and other players aged ' + ac.age_group));

        const kpiGrid = el('div', 'kpiGrid twoCol');
        [
            { num: String(ac.group_games), lbl: 'Finished runs (' + ac.age_group + ')', accent: true },
            { num: String(ac.all_games), lbl: 'Total finished runs (all ages)' }
        ].forEach(function (k) {
            const card = el('div', 'kpiCard' + (k.accent ? ' accent' : ''));
            card.appendChild(el('div', 'kpiNum', k.num));
            card.appendChild(el('div', 'kpiLbl', k.lbl));
            kpiGrid.appendChild(card);
        });
        box.appendChild(kpiGrid);

        if (!ac.enough) {
            box.appendChild(el('p', 'dashText',
                'Only ' + ac.group_games + ' finished game' + (ac.group_games === 1 ? '' : 's') + ' from players aged ' +
                ac.age_group + ' so far. The comparison appears once ' + ac.min_sample + ' have finished.'));
        } else {
            box.appendChild(el('p', 'dashText',
                'The main theme of each finished game. Players aged ' + ac.age_group + ' (' + ac.group_games +
                ' games) against all players (' + ac.all_games + ' games).'));
            box.appendChild(buildAgeChart(ac));
        }
        return box;
    }

    function renderDashboard(o) {
        story.body.replaceChildren();
        const wrap = el('div', 'dash');

        const title = el('h2', 'dashTitle', 'What the national figures say');
        title.tabIndex = -1;
        wrap.appendChild(title);
        wrap.appendChild(el('p', 'dashLead',
            'This is not a prediction. It shows how often couples in Singapore gave each reason when they divorced in ' +
            o.year + '.'));

        // Build the carousel slides
        const slides = [];
        if (o.ladder && o.ladder.length) {
            slides.push({ label: '1. Warning Signs', render: function () { return buildLadder(o); } });
        }
        slides.push({ label: '2. Your Themes', render: function () { return buildThemesSlide(o); } });
        slides.push({ label: '3. SG Figures', render: function () { return buildNationalSlide(o); } });
        if (o.age_comparison) {
            slides.push({ label: '4. Age Peers', render: function () { return buildAgeSlide(o.age_comparison); } });
        }

        let currentSlide = 0;

        // Top slide tabs
        const tabs = el('div', 'carouselTabs');
        tabs.setAttribute('role', 'tablist');
        const tabButtons = slides.map(function (s, idx) {
            const btn = el('button', 'carouselTab', s.label);
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.addEventListener('click', function () { goToSlide(idx); });
            tabs.appendChild(btn);
            return btn;
        });
        wrap.appendChild(tabs);

        // Active slide stage
        const stage = el('div', 'carouselStage');
        wrap.appendChild(stage);

        // Bottom carousel navigation (Prev / Dots / Next)
        const nav = el('div', 'carouselNav');
        const prevBtn = el('button', 'storyChoice carouselBtn', '← Previous');
        prevBtn.type = 'button';
        prevBtn.addEventListener('click', function () {
            if (currentSlide > 0) goToSlide(currentSlide - 1);
        });

        const dots = el('div', 'carouselDots');
        const dotButtons = slides.map(function (s, idx) {
            const d = el('button', 'carouselDot');
            d.type = 'button';
            d.setAttribute('aria-label', 'Go to ' + s.label);
            d.addEventListener('click', function () { goToSlide(idx); });
            dots.appendChild(d);
            return d;
        });

        const nextBtn = el('button', 'storyChoice carouselBtn', 'Next →');
        nextBtn.type = 'button';
        nextBtn.addEventListener('click', function () {
            if (currentSlide < slides.length - 1) goToSlide(currentSlide + 1);
        });

        nav.append(prevBtn, dots, nextBtn);
        wrap.appendChild(nav);

        // Always-available Play Again button at the bottom
        const again = el('button', 'storyChoice endingBtn', 'Play again');
        again.type = 'button';
        again.addEventListener('click', playAgain);
        wrap.appendChild(again);

        function goToSlide(idx) {
            currentSlide = Math.max(0, Math.min(slides.length - 1, idx));
            stage.replaceChildren();
            const slideBody = el('div', 'carouselSlide');
            slideBody.appendChild(slides[currentSlide].render());
            stage.appendChild(slideBody);

            tabButtons.forEach(function (b, i) {
                const active = i === currentSlide;
                b.classList.toggle('active', active);
                b.setAttribute('aria-selected', String(active));
            });
            dotButtons.forEach(function (d, i) {
                d.classList.toggle('active', i === currentSlide);
            });

            prevBtn.disabled = currentSlide === 0;
            nextBtn.disabled = currentSlide === slides.length - 1;
            nextBtn.textContent = currentSlide === slides.length - 1
                ? 'End of summary'
                : 'Next: ' + slides[currentSlide + 1].label.replace(/^\d+\.\s*/, '') + ' →';

            if (story && story.root) {
                story.root.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
            }
        }

        if (dashKeyHandler) {
            document.removeEventListener('keydown', dashKeyHandler);
        }
        dashKeyHandler = function (ev) {
            if (!story || !story.root.classList.contains('dashMode')) return;
            if (ev.key === 'ArrowRight' && currentSlide < slides.length - 1) goToSlide(currentSlide + 1);
            if (ev.key === 'ArrowLeft' && currentSlide > 0) goToSlide(currentSlide - 1);
        };
        document.addEventListener('keydown', dashKeyHandler);

        story.body.appendChild(wrap);
        goToSlide(0);
        title.focus({ preventScroll: true });
    }

    // Replace the placeholders from rooms.js
    window.enterRoom = enterRoom;
    window.showEnding = showEnding;

    let replay = null;
    let loadSession = null;
    try {
        replay = sessionStorage.getItem('wvReplay');
        loadSession = sessionStorage.getItem('wvLoadSession');
        sessionStorage.removeItem('wvReplay');
        sessionStorage.removeItem('wvLoadSession');
    } catch (e) { }

    if (loadSession) {
        setSound(replay !== 'off');
        startAccountFlow(loadSession);
    } else if (replay) {
        setSound(replay === 'on');
        startAccountFlow(true);
    } else {
        showContentWarning();
    }
})();