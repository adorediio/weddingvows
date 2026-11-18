// =====================================================
//  SCENE
//  Canvas setup, chapel background, door frame, doors and the clicks that open the doors / enter the chapel.
// =====================================================

// Connect Fabric.js with the existing canvas element in index.html
const canvas = new fabric.Canvas('weddingCanvas');

// Store door images
let leftDoor;
let rightDoor;
let doorFrame;
let doorsOpening = false;
let chapelBackground;

// Set canvas size to fit the browser window
function resizeCanvas() {
    canvas.setWidth(window.innerWidth);
    canvas.setHeight(window.innerHeight);
    canvas.renderAll();
}

// Set the initial canvas size
resizeCanvas();

// Resize canvas when browser window changes
window.addEventListener('resize', resizeCanvas);

// Set the canvas background colour
canvas.backgroundColor = '#f8eded';

// Display the canvas
canvas.renderAll();

// Load the chapel background
fabric.Image.fromURL('images/chapel4.png', function (img) {

    chapelBackground = img;

    // Calculate the scale needed to cover the entire canvas
    const scale = Math.max(
        canvas.width / img.width,
        canvas.height / img.height
    );

    // Resize and position the chapel image
    img.set({
        scaleX: scale,
        scaleY: scale,

        left: canvas.width / 2,
        top: canvas.height / 2,

        originX: 'center',
        originY: 'center',

        selectable: false,
        evented: false
    });

    // Add the chapel to the canvas and keep it behind everything else
    canvas.add(img);
    canvas.sendToBack(img);

    canvas.renderAll();
});

// Load the door frame
fabric.Image.fromURL('images/door_frame.png', function (frame) {

    // Store the door frame so we can access it
    doorFrame = frame;

    // Calculate the scale to cover the canvas
    const scale = Math.max(
        canvas.width / frame.width,
        canvas.height / frame.height
    );

    // Resize and position the frame
    frame.set({
        scaleX: scale,
        scaleY: scale,
        left: (canvas.width - frame.width * scale) / 2,
        top: (canvas.height - frame.height * scale) / 2,

        selectable: false,
        evented: false
    });

    canvas.add(frame);
    canvas.renderAll();
});

// Load the left door
fabric.Image.fromURL('images/left_door.png', function (img) {

    leftDoor = img;

    // Use the same scale as the decorative frame
    const scale = Math.max(
        canvas.width / 913,
        canvas.height / 685
    );

    // Calculate the frame position
    const frameLeft = (canvas.width - 913 * scale) / 2;
    const frameTop = (canvas.height - 685 * scale) / 2;

    // Resize and position the left door
    img.set({
        scaleX: scale,
        scaleY: scale,

        left: frameLeft + (290 * scale),
        top: frameTop + (50 * scale) + (img.height * scale / 2),

        originX: 'left',
        originY: 'center',

        selectable: false,
        evented: false
    });

    canvas.add(img);
    canvas.renderAll();
});

// Load the right door
fabric.Image.fromURL('images/right_door.png', function (img) {

    rightDoor = img;

    // Use the same scale as the decorative frame
    const scale = Math.max(
        canvas.width / 913,
        canvas.height / 685
    );

    // Calculate the frame position
    const frameLeft = (canvas.width - 913 * scale) / 2;
    const frameTop = (canvas.height - 685 * scale) / 2;

    // Resize and position the right door
    img.set({
        scaleX: scale,
        scaleY: scale,

        left: frameLeft + (460 * scale) + (img.width * scale),
        top: frameTop + (50 * scale) + (img.height * scale / 2),

        originX: 'right',
        originY: 'center',

        selectable: false,
        evented: false
    });

    canvas.add(img);

    // Create a glowing white circle (guides the user to press to open the door)
    const clickIndicator = new fabric.Circle({
        radius: 18,
        fill: 'transparent',
        stroke: 'white',
        strokeWidth: 2,
        left: canvas.width / 2 + 4,
        top: canvas.height / 2 + 30,
        originX: 'center',
        originY: 'center',

        selectable: false,
        evented: true
    });

    canvas.add(clickIndicator);
    canvas.bringToFront(clickIndicator);

    // Create an invisible clickable area for entering the chapel
    const entranceArea = new fabric.Rect({
        left: canvas.width / 2 - 127,
        top: canvas.height / 2 - 200,

        width: 255,
        height: 450,

        fill: 'transparent',

        selectable: false,
        evented: true
    });

    canvas.add(entranceArea);
    entranceArea.set('visible', false);

    // Click the doorway to enter the chapel, then start the cutscene
    entranceArea.on('mousedown', function () {

        // Wedding song starts as she walks in
        startWeddingSong();

        // Prevent the player from clicking again
        entranceArea.set('evented', false);

        // Hide the doorway and doors
        doorFrame.set('visible', false);
        leftDoor.set('visible', false);
        rightDoor.set('visible', false);

        // Zoom into the chapel, then show the glowing circle
        chapelBackground.animate('scaleY', chapelBackground.scaleY * 1.10, { duration: 800 });
        chapelBackground.animate('scaleX', chapelBackground.scaleX * 1.10, {
            duration: 800,
            onChange: () => canvas.renderAll(),
            onComplete: showChapelIndicator
        });
    });

    // Open the doors when the indicator is clicked
    clickIndicator.on('mousedown', function () {

        // Prevent repeated clicks
        if (doorsOpening) return;
        doorsOpening = true;

        // Door creak sound as the doors open
        playDoorCreak();

        // Hide the indicator
        clickIndicator.set('visible', false);

        // Make the left door appear to swing inward
        leftDoor.animate('scaleX', scale * 0.08, {
            duration: 7000,
            onChange: () => canvas.renderAll()
        });

        // Make the right door appear to swing inward
        rightDoor.animate('scaleX', scale * 0.08, {
            duration: 7000,
            onChange: () => canvas.renderAll(),
            onComplete: function () {
                // Show clickable entrance
                entranceArea.set('visible', true);
                canvas.bringToFront(entranceArea);
                canvas.renderAll();
            }
        });
    });

    canvas.renderAll();
});
