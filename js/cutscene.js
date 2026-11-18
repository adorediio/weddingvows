// =====================================================
//  CUTSCENE
//  Glowing circle at the altar -> zoom -> blinks -> room choices
// =====================================================

function showChapelIndicator() {
    let clicked = false;

    // Glowing circle in the chapel (adjust left/top to sit on your aisle/altar)
    const glow = new fabric.Circle({
        radius: 20,
        fill: 'rgba(255,255,255,0.15)',
        stroke: 'white',
        strokeWidth: 2,
        left: canvas.width / 2 - 15,
        top: canvas.height / 2 - 70,
        originX: 'center',
        originY: 'center',
        selectable: false,
        evented: true,
        hoverCursor: 'pointer',
        shadow: new fabric.Shadow({ color: 'white', blur: 25 })
    });
    canvas.add(glow);
    canvas.bringToFront(glow);

    // Pulse loop
    function pulse() {
        if (clicked) return;
        glow.animate('opacity', 0.35, {
            duration: 800,
            onChange: () => canvas.renderAll(),
            onComplete: () => glow.animate('opacity', 1, {
                duration: 800,
                onChange: () => canvas.renderAll(),
                onComplete: pulse
            })
        });
    }
    pulse();

    glow.on('mousedown', function () {
        if (clicked) return;
        clicked = true;

        // Wedding song fades out, breathing + heartbeat fade in
        startBreathingHeartbeat();

        canvas.remove(glow);

        // Zoom in closer
        chapelBackground.animate('scaleX', chapelBackground.scaleX * 1.35, {
            duration: 1800,
            easing: fabric.util.ease.easeInOutQuad
        });
        chapelBackground.animate('scaleY', chapelBackground.scaleY * 1.35, {
            duration: 1800,
            easing: fabric.util.ease.easeInOutQuad,
            onChange: () => canvas.renderAll(),
            onComplete: startBlinkSequence
        });
    });
}

// Eyelids = two black rects that close/open
function blink(onClosed, onOpened) {
    const h = canvas.height, w = canvas.width;

    const topLid = new fabric.Rect({
        left: 0, top: 0, width: w, height: 0,
        fill: 'black', selectable: false, evented: false
    });
    const bottomLid = new fabric.Rect({
        left: 0, top: h, width: w, height: 0, originY: 'bottom',
        fill: 'black', selectable: false, evented: false
    });
    canvas.add(topLid, bottomLid);
    canvas.bringToFront(topLid);
    canvas.bringToFront(bottomLid);

    const dur = 350;
    const opts = (extra) => Object.assign({ duration: dur, onChange: () => canvas.renderAll() }, extra);

    // close
    bottomLid.animate('height', h / 2, opts());
    topLid.animate('height', h / 2, opts({
        onComplete: function () {
            if (onClosed) onClosed();            // swap the scene while eyes are shut
            // open
            bottomLid.animate('height', 0, opts());
            topLid.animate('height', 0, opts({
                onComplete: function () {
                    canvas.remove(topLid, bottomLid);
                    if (onOpened) onOpened();
                }
            }));
        }
    }));
}

function startBlinkSequence() {
    // Blink 1: quick, nothing changes. Blink 2: background turns blurry (eyes adjusting)
    blink(null, function () {
        setTimeout(function () {
            blink(function () {
                chapelBackground.filters = [new fabric.Image.filters.Blur({ blur: 0.25 })];
                chapelBackground.applyFilters();
                canvas.renderAll();
            }, showRoomChoices);
        }, 250);
    });
}
