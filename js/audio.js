const doorCreak = new Audio('audio/doorcreak.mp4');          // plays when the doors open
doorCreak.volume = 0.8;

const weddingSong = new Audio('audio/herecomesthebride.mp4'); // plays when she walks in
weddingSong.loop = true;
weddingSong.volume = 0;

const breathing = new Audio('audio/panic.mp4');               // plays when she clicks the altar
breathing.loop = true;
breathing.volume = 0;

const heartbeat = new Audio('audio/heartbeat.mp4');           // plays together with breathing
heartbeat.loop = true;
heartbeat.volume = 0.8;

// Smoothly change an audio element's volume
function fadeAudio(audio, target, ms) {
    const steps = 20;
    const stepTime = ms / steps;
    const delta = (target - audio.volume) / steps;
    let count = 0;
    const timer = setInterval(function () {
        count++;
        audio.volume = Math.min(1, Math.max(0, audio.volume + delta));
        if (count >= steps) {
            audio.volume = target;
            clearInterval(timer);
            if (target === 0) audio.pause();
        }
    }, stepTime);
}

// Door creak (called on the first click, which also lets the browser play sound)
function playDoorCreak() {
    doorCreak.currentTime = 0;
    doorCreak.play().catch(function (e) { console.log('Audio blocked or file missing:', e); });
}

// Wedding song fades in when she walks into the chapel
function startWeddingSong() {
    weddingSong.play().then(function () {
        fadeAudio(weddingSong, 0.5, 2000);
    }).catch(function (e) { console.log('Audio blocked or file missing:', e); });
}

// Wedding song fades out, breathing + heartbeat fade in together
function startBreathingHeartbeat() {
    fadeAudio(weddingSong, 0, 2000);

    breathing.play().then(function () {
        fadeAudio(breathing, 0.7, 1500);
    }).catch(function (e) { console.log('Audio blocked or file missing:', e); });

    heartbeat.play().then(function () {
        fadeAudio(heartbeat, 0.6, 1500);
    }).catch(function (e) { console.log('Audio blocked or file missing:', e); });
}

// Called from showEnding(): fades out both sounds and removes the pulse
function stopTension() {
    fadeAudio(breathing, 0, 1500);
    fadeAudio(heartbeat, 0, 1500);
    const pulse = document.getElementById('pulseOverlay');
    if (pulse) pulse.remove();
}