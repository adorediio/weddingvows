const coll = db.game_sessions_bench;
coll.drop();
const scenes = Array.from({length: 40}, (_, i) => "scene_" + String(i + 1).padStart(2, "0"));
let batch = [];
for (let i = 0; i < 30000; i++) {
  const n = 5 + Math.floor(Math.random() * 15);
  const choices = Array.from({length: n}, () => {
    const s = scenes[Math.floor(Math.random() * scenes.length)];
    return {
      sceneID: s,
      choiceID: "r" + (1 + Math.floor(Math.random() * 4)) + "_" + String(1 + Math.floor(Math.random() * 20)).padStart(2, "0"),
      nextScene: s
    };
  });
  batch.push({
    sessionID: "bench" + i,
    playerID: "p" + (i % 5000),
    slot: 1 + (i % 5),
    started_at: new Date(),
    completed_at: Math.random() < 0.5 ? new Date() : null,
    tension_points: Math.floor(Math.random() * 100),
    choices: choices
  });
  if (batch.length === 2000) { coll.insertMany(batch); batch = []; }
}
print("documents inserted: " + coll.countDocuments());