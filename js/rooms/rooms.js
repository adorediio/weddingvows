// =====================================================
//  ROOM CHOICES
// =====================================================

// locked: true = greyed out with a lock and can't be clicked.
// Call unlockRoom(id) later (e.g. unlockRoom(2);) when your condition is met.
const rooms = [
    { id: 1, title: 'Happy Family',    sub: 'Where it all began', img: 'images/card1.png', locked: false },
    { id: 2, title: 'Temptation',      sub: 'A wandering eye',    img: 'images/card2.png', locked: true },
    { id: 3, title: 'Phone Discovery', sub: 'The messages',       img: 'images/card3.png', locked: true },
    { id: 4, title: 'Argument',        sub: 'Voices rise',        img: 'images/card4.png', locked: true }
];
const visitedRooms = new Set();
const cardEls = {};   // room id -> its card element

// Update every card's look from its locked flag
function refreshLocks() {
    rooms.forEach(function (room) {
        const card = cardEls[room.id];
        if (!card) return;
        card.classList.toggle('locked', room.locked);
        card.classList.toggle('visited', visitedRooms.has(room.id));
    });
}

// Call this from anywhere, whenever your unlock condition is met, e.g. unlockRoom(2);
function unlockRoom(id) {
    const room = rooms.find(r => r.id === id);
    if (room) {
        room.locked = false;
        refreshLocks();
    }
}

// Call this when the player finishes a room
function completeRoom(id) {
    visitedRooms.add(id);
    refreshLocks();
    if (visitedRooms.size === rooms.length) showEnding();
}

function showRoomChoices() {
    // Pulsing background
    const pulseOverlay = document.createElement('div');
    pulseOverlay.id = 'pulseOverlay';
    document.body.appendChild(pulseOverlay);

    // Text above the cards
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
        card.innerHTML = `<div class="roomNum">${room.id}</div><h3>${room.title}</h3><p>${room.sub}</p>`;

        // Lock icon (only visible while the card is locked)
        const lock = document.createElement('div');
        lock.className = 'lockIcon';
        lock.textContent = '🔒';
        card.appendChild(lock);

        cardEls[room.id] = card;

        card.addEventListener('click', function () {
            if (room.locked) return;   // locked = ignore clicks
            enterRoom(room.id);
        });
        box.appendChild(card);
    });

    document.body.appendChild(box);

    // Apply the locked look to the cards
    refreshLocks();
    if (typeof window.loadHubPulse === 'function') window.loadHubPulse();
}

//function enterRoom(id) {
    //console.log('Entering room', id);   // TODO: load each room's scene here
//}

function showEnding() {
    stopTension();
    console.log('All rooms visited, show the divorce stats dashboard');   // TODO
}