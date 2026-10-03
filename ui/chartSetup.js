import { Chart, LineController, LineElement, PointElement, BarController, BarElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler } from 'chart.js';

// One registration for every chart in the app (Chart.js is tree-shaken: only these parts ship).
Chart.register( LineController, LineElement, PointElement, BarController, BarElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler );

export { Chart };
