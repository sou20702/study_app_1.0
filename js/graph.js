// ==========================================
// index.php の <canvas id="myChart"> に円グラフを描画する
// ==========================================
// メモ：ここではまだ実データ（DBの進行度）を受け取っていないため、
// 動作確認用の仮データで描画しています。
// task_reward.php のように PHP 側で
//   window.taskChartData = <?php echo json_encode($data); ?>;
// のような形でデータを渡すようにすれば、そのまま実データに差し替えられます。

document.addEventListener("DOMContentLoaded", function () {
    const canvas = document.getElementById("myChart");
    if (!canvas) return;

    // 実データが window.dashboardChartData 等で渡されていればそれを使い、
    // 無ければ仮データ（A/B/C）を使う
    const chartData = window.dashboardChartData || [
        { label: "A", value: 30, color: "#e74c3c" },
        { label: "B", value: 20, color: "#2ecc71" },
        { label: "C", value: 50, color: "#3498db" }
    ];

    drawPieChart(canvas, chartData);

    function drawPieChart(canvas, data) {
        const size = 300;         // 円グラフの直径
        const padding = 20;
        const legendWidth = 140;  // 凡例スペース

        canvas.width = size + padding * 2 + legendWidth;
        canvas.height = size + padding * 2;

        const ctx = canvas.getContext("2d");
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const total = data.reduce((sum, d) => sum + d.value, 0);
        if (total <= 0) return;

        const centerX = padding + size / 2;
        const centerY = padding + size / 2;
        const radius = size / 2;

        let startAngle = -Math.PI / 2; // 12時の位置から開始

        data.forEach((d, i) => {
            const sliceAngle = (d.value / total) * Math.PI * 2;
            const endAngle = startAngle + sliceAngle;

            // 扇形を描画
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle, endAngle);
            ctx.closePath();
            ctx.fillStyle = d.color || `hsl(${(i * 67) % 360}, 65%, 55%)`;
            ctx.fill();

            startAngle = endAngle;
        });

        // 凡例（ラベルと割合）
        const legendX = padding * 2 + size;
        let legendY = padding + 10;

        ctx.font = "14px sans-serif";
        ctx.textBaseline = "middle";

        data.forEach((d, i) => {
            const percent = Math.round((d.value / total) * 100);

            ctx.fillStyle = d.color || `hsl(${(i * 67) % 360}, 65%, 55%)`;
            ctx.fillRect(legendX, legendY - 7, 14, 14);

            ctx.fillStyle = "#333";
            ctx.fillText(`${d.label} (${percent}%)`, legendX + 20, legendY);

            legendY += 24;
        });
    }
});
