// =====================================================
//  ROOM CHOICES
// =====================================================

// What the player's choices have added up to so far.
// flags = things the player did (set by "sets" in the room files).
// forced = a jump set by a choice or ending, used when the room ends.
const fate = { heat: 0, scores: {}, flags: {}, forced: null };

function recordChoice(c) {
    fate.heat += (c.heat !== undefined ? c.heat : (c.tension || 0));
    if (c.reason && (c.weight || 0) > 0) {
        fate.scores[c.reason] = (fate.scores[c.reason] || 0) + c.weight;
    }
    (c.sets || []).forEach(function (f) { fate.flags[f] = true; });
}

// What an "opens" rule gets to look at. extraDone = a room about to be marked done.
function fateState(extraDone) {
    return {
        heat: fate.heat,
        done: function (id) { return visitedRooms.has(id) || id === extraDone; },
        score: function (reason) { return fate.scores[reason] || 0; },
        flag: function (name) { return !!fate.flags[name]; },
        points: function () {
            return Object.values(fate.scores).reduce(function (a, b) { return a + b; }, 0);
        }
    };
}

// locked: true = greyed out with a lock and can't be clicked.
// opens = the rule that unlocks the card. Once unlocked, a card stays unlocked.
const rooms = [
    { id: 1, title: 'Happy Family',    sub: 'Where it all began', img: 'images/card1.png', locked: false },
    { id: 2, title: 'Temptation',      sub: 'A wandering eye',    img: 'images/card2.png', locked: true,
      opens: function (s) { return s.done(1) && (s.heat >= 1 || s.points() >= 1) && s.score('Divorce By Mutual Agreement') < 2 && s.score('Personality Differences') < 4; } },
    { id: 3, title: 'Phone Discovery', sub: 'The messages',       img: 'images/card3.png', locked: true,
      opens: function (s) { return s.done(2) && s.score('Adultery & Infidelity') >= 2; } },
    { id: 4, title: 'Argument',        sub: 'Voices rise',        img: 'images/card4.png', locked: true,
      opens: function (s) { return (s.done(2) || s.done(3)) && (s.heat >= 5 || s.score('Domestic Violence & Abuses') >= 2); } }
];
const visitedRooms = new Set();
const cardEls = {};   // room id -> its card element

function refreshLocks() {
    rooms.forEach(function (room) {
        const card = cardEls[room.id];
        if (!card) return;
        card.classList.toggle('locked', room.locked);
        card.classList.toggle('visited', visitedRooms.has(room.id));
    });
}

function unlockRoom(id) {
    const room = rooms.find(function (r) { return r.id === id; });
    if (room) {
        room.locked = false;
        refreshLocks();
    }
}

// Call this when the player finishes a room
function completeRoom(id) {
    visitedRooms.add(id);
    applyUnlocks();
    if (!hasOpenRoom()) showEnding();   // nothing left to open = the story is over
}

function applyUnlocks() {
    const s = fateState();
    rooms.forEach(function (r) {
        if (r.locked && r.opens && r.opens(s)) r.locked = false;
    });
    refreshLocks();
}

function hasOpenRoom() {
    return rooms.some(function (r) { return !r.locked && !visitedRooms.has(r.id); });
}

// True if finishing room `id` leaves nothing to play (used for the button label)
function isLastRoom(id) {
    const s = fateState(id);
    return !rooms.some(function (r) {
        return r.id !== id && !visitedRooms.has(r.id) && (!r.locked || (r.opens && r.opens(s)));
    });
}

function showRoomChoices() {
    const pulseOverlay = document.createElement('div');
    pulseOverlay.id = 'pulseOverlay';
    document.body.appendChild(pulseOverlay);

    const prompt = document.createElement('div');
    prompt.id = 'roomPrompt';
    prompt.innerHTML = 'Are you second guessing your marriage? <small>Do these look familiar?</small>';
    document.body.appendChild(prompt);

    const box = document.createElement('div');
    box.id = 'roomChoices';

    rooms.forEach(function (room, i) {
        const card = document.createElement('div');
        card.className = 'roomCard';
        card.style.backgroundImage = 'linear-gradient(rgba(255,255,255,0.55), rgba(255,255,255,0.55)), url(' + room.img + ')';
        card.style.backgroundSize = 'cover';
        card.style.backgroundPosition = 'center';
        card.style.animationDelay = (i * 0.15) + 's';
        card.innerHTML = '<div class="roomNum">' + room.id + '</div><h3>' + room.title + '</h3><p>' + room.sub + '</p>';

        const lock = document.createElement('div');
        lock.className = 'lockIcon';
        lock.textContent = '🔒';
        card.appendChild(lock);

        cardEls[room.id] = card;
        card.addEventListener('click', function () {
            if (room.locked) return;
            enterRoom(room.id);
        });
        box.appendChild(card);
    });

    document.body.appendChild(box);
    refreshLocks();
    if (typeof window.loadHubPulse === 'function') window.loadHubPulse();
}

// enterRoom() and showEnding() are defined in game.js