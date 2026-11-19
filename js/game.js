// =====================================================
//  GAME
//  Content warning, room stories (rooms/room{n}.json), API calls,
//  tension effects, support screen and the final statistics dashboard.
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
        offline: false,        // true once an API call has failed
        room: null,            // the loaded room JSON
        roomId: null,
        sceneID: null,
        tensionPoints: 0,
        busy: false
    };
    const roomCache = {};

    // Sound effects played by scenes ("sfx" in the room JSON). Put your files in /audio
    // and change the file names here. A missing file is skipped (one console warning).
    const SFX_FILES = {
        shouting:       'audio/arguement.mp4',      // the two of them arguing
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
    let story = null;          // { root, body } for the open story overlay

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

    // Guest player + session, created the first time a choice is saved
    async function ensureSession() {
        if (game.sessionID) return game.sessionID;
        const tag = Math.random().toString(36).slice(2, 8);
        const player = await api('POST', '/players', {
            username: 'guest_' + tag,
            email: 'guest_' + tag + '@wedding.local'
        });
        const session = await api('POST', '/sessions', { playerID: player.playerID });
        game.sessionID = session.sessionID;
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
        ['roomChoices', 'roomPrompt'].forEach(function (id) {
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
            });
            actions.appendChild(b);
        });
        card.appendChild(actions);
        wrap.appendChild(card);
        document.body.appendChild(wrap);
        actions.firstChild.focus({ preventScroll: true });
    }

    // ---------- entering a room and playing scenes ----------

    async function enterRoom(id) {
        if (game.busy || visitedRooms.has(id)) return;
        game.busy = true;
        setCardsVisible(false);
        openStory();
        try {
            game.room = await loadRoom(id);
            game.roomId = id;
            setTension(0);
            game.busy = false;
            renderScene(game.room.start);
        } catch (e) {
            game.busy = false;
            console.error('Could not open room ' + id + ':', e);
            showMessage(
                'This room could not be loaded (' + e.message + '). Check that the rooms folder sits next to index.html, then try again.',
                [
                    { label: 'Try again', run: function () { enterRoom(id); } },
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

        // The API decides the next scene. If it cannot be reached, the room file is used.
        let next = choice.next;
        try {
            await ensureSession();
            const res = await api('POST', '/choices', {
                sessionID: game.sessionID,
                choiceID: choice.id,
                sceneID: game.sceneID
            });
            next = res.next_scene || next;
        } catch (e) {
            game.offline = true;
            console.warn('API unavailable, continuing from the room file:', e.message);
        }

        await wait(450);
        game.busy = false;
        renderScene(next);
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

    function finishRoom() {
        const id = game.roomId;
        closeStory();
        restoreCardAudio();
        unlockRoom(id + 1);
        completeRoom(id);   // calls showEnding() once all four rooms are done
        if (visitedRooms.size < rooms.length) setCardsVisible(true);
    }

    // ---------- final dashboard ----------

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
                    { label: 'Play again', run: function () { location.reload(); } }
                ]
            );
        }
    }

    function percent(n) {
        return Number(n).toFixed(1) + '%';
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

        // Themes the player met
        const themes = Object.entries(o.scores || {});
        wrap.appendChild(el('h3', 'dashHead', 'Themes your story touched'));
        if (themes.length === 0) {
            wrap.appendChild(el('p', 'dashText', 'Your choices stayed mostly neutral, so no single theme stood out.'));
        } else {
            const ul = el('ul', 'dashThemes');
            themes.forEach(function (t) {
                ul.appendChild(el('li', null, t[0] + ' (' + t[1] + (t[1] === 1 ? ' point)' : ' points)')));
            });
            wrap.appendChild(ul);
        }

        // The player's main theme against the national row
        if (o.national_match) {
            const m = o.national_match;
            wrap.appendChild(el('p', 'dashText',
                'Your story touched most on ' + m.reason.toLowerCase() + '. In ' + o.year + ', ' + percent(m.percent) +
                ' of divorces in Singapore cited it (' + m.count.toLocaleString() + ' divorces), the number ' +
                m.rank + ' reason.'));
        }

        // Bars: national top three, plus the player's main reason if it is not among them
        const rows = (o.national_top3 || []).slice();
        if (o.national_match && !rows.some(function (r) { return r.reason === o.national_match.reason; })) {
            rows.push(o.national_match);
        }
        wrap.appendChild(el('h3', 'dashHead', 'Most common reasons in ' + o.year));
        const max = Math.max.apply(null, rows.map(function (r) { return Number(r.percent); }).concat([1]));
        rows.forEach(function (r) {
            const row = el('div', 'bar' + (o.main_reason === r.reason ? ' mine' : ''));
            row.appendChild(el('span', 'barLabel', r.reason));
            const track = el('span', 'barTrack');
            const fill = el('span', 'barFill');
            fill.style.width = (Number(r.percent) / max * 100) + '%';
            track.appendChild(fill);
            row.appendChild(track);
            row.appendChild(el('span', 'barValue', percent(r.percent)));
            wrap.appendChild(row);
        });

        wrap.appendChild(el('p', 'dashSource', 'Source: Singapore Department of Statistics (SingStat).'));

        const again = el('button', 'storyChoice endingBtn', 'Play again');
        again.type = 'button';
        again.addEventListener('click', function () { location.reload(); });
        wrap.appendChild(again);

        story.body.appendChild(wrap);
        title.focus({ preventScroll: true });
    }

    // Replace the placeholders from rooms.js
    window.enterRoom = enterRoom;
    window.showEnding = showEnding;

    showContentWarning();
})();