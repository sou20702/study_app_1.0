// Canvasを取得
const canvas = document.getElementById("mailCanvas");

// 2D描画モード
const ctx = canvas.getContext("2d");

// 描画中かどうか
let drawing = false;

// 線の太さ
ctx.lineWidth = 3;

// 線の色
ctx.strokeStyle = "black";

// 線の端を丸くする
ctx.lineCap = "round";

// 画面上の位置 → キャンバス内の座標（CSSで縮小表示されても合うように補正）
function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    return {
        x: (e.clientX - rect.left) * (canvas.width / rect.width),
        y: (e.clientY - rect.top) * (canvas.height / rect.height)
    };
}

canvas.addEventListener("pointerdown", function (e) {
    drawing = true;
    canvas.setPointerCapture(e.pointerId);
    const p = getPos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
});

canvas.addEventListener("pointermove", function (e) {
    if (!drawing) {
        return;
    }
    const p = getPos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
});

function stopDrawing() {
    drawing = false;
}
canvas.addEventListener("pointerup", stopDrawing);
canvas.addEventListener("pointercancel", stopDrawing);

const clearButton = document.getElementById("clearCanvas");

clearButton.addEventListener("click", function () {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
});
