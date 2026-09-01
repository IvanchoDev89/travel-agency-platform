jQuery(function($) {
  if (typeof Chart === 'undefined') return;

  Chart.defaults.font.family = '-apple-system, Segoe UI, Roboto, sans-serif';

  var CYAN = 'rgba(13, 148, 136, 1)';
  var AMBER = 'rgba(217, 119, 6, 1)';
  var statusColors = {
    pending:   '#d97706',
    confirmed: '#16a34a',
    completed: '#2563eb',
    cancelled: '#dc2626',
    refunded:  '#9333ea'
  };

  // Revenue + bookings monthly (dual axis)
  if ($('#tap-chart-revenue').length) {
    var rev = tapDash.monthly.revenue || [];
    var bks = tapDash.monthly.bookings || [];
    new Chart($('#tap-chart-revenue'), {
      type: 'bar',
      data: {
        labels: tapDash.monthly.labels || [],
        datasets: [
          {
            label: 'Ingresos (' + (tapDash.currency || 'USD') + ')',
            data: rev,
            backgroundColor: 'rgba(13, 148, 136, 0.18)',
            borderColor: CYAN,
            borderWidth: 2,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            label: 'Reservas',
            type: 'line',
            data: bks,
            borderColor: AMBER,
            backgroundColor: AMBER,
            pointRadius: 3,
            pointBackgroundColor: AMBER,
            tension: 0.3,
            borderWidth: 2,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { callback: function(v) { return v.toLocaleString(); } }
          },
          y1: {
            beginAtZero: true,
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { precision: 0 }
          }
        },
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  // Status donut
  if ($('#tap-chart-status').length) {
    var counts = tapDash.status[0] || [];
    var keys = tapDash.status[1] || [];
    var labels = {
      pending: 'Pendiente', confirmed: 'Confirmada', completed: 'Completada',
      cancelled: 'Cancelada', refunded: 'Reembolsada'
    };
    new Chart($('#tap-chart-status'), {
      type: 'doughnut',
      data: {
        labels: keys.map(function(k) { return labels[k] || k; }),
        datasets: [{
          data: counts,
          backgroundColor: keys.map(function(k) { return statusColors[k] || '#94a3b8'; }),
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  // Service type breakdown donut
  if ($('#tap-chart-breakdown').length) {
    var bd = tapDash.breakdown || { labels: [], data: [] };
    var palette = ['#0d9488', '#d97706', '#7c3aed', '#0ea5e9', '#f43f5e', '#10b981'];
    new Chart($('#tap-chart-breakdown'), {
      type: 'doughnut',
      data: {
        labels: bd.labels,
        datasets: [{
          data: bd.data,
          backgroundColor: bd.labels.map(function(_, i) { return palette[i % palette.length]; }),
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }
});
