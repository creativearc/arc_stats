<style>
.arc-stats__msg { padding: 10px 0; color: var(--ee-text-secondary); }
.arc-stats__msg--error { color: var(--ee-error); }
.arc-stats__range { margin: 0 0 12px; font-size: 0.85em; color: var(--ee-text-secondary); }

.arc-stats__tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
.arc-stats__tile { padding: 12px; border: 1px solid var(--ee-border); border-radius: 6px; }
.arc-stats__label { font-size: 0.85em; color: var(--ee-text-secondary); }
.arc-stats__value { font-size: 1.6em; font-weight: 600; color: var(--ee-text-primary); line-height: 1.3; font-variant-numeric: tabular-nums; }
.arc-stats__change { font-size: 0.8em; font-variant-numeric: tabular-nums; }
.arc-stats__change--good { color: var(--ee-success); }
.arc-stats__change--bad  { color: var(--ee-error); }
.arc-stats__change--flat { color: var(--ee-text-secondary); }

.arc-stats__table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
.arc-stats__table th { text-align: left; font-weight: 500; font-size: 0.85em; color: var(--ee-text-secondary); padding: 0 6px 6px; border-bottom: 1px solid var(--ee-border); }
.arc-stats__table td { padding: 6px; border-bottom: 1px solid var(--ee-border); }
.arc-stats__table tr:last-child td { border-bottom: 0; }
.arc-stats__table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
/* Proportional bar behind the label, scaled to the top row. */
.arc-stats__bar { position: relative; overflow-wrap: anywhere; }
.arc-stats__bar::before { content: ""; position: absolute; inset: 2px auto 2px 0; width: var(--w); background: var(--ee-bg-5); border-radius: 3px; z-index: 0; }
.arc-stats__bar span { position: relative; z-index: 1; }

.arc-stats__gap { height: 16px; }

.arc-stats__reasons { list-style: none; margin: 0; padding: 0; }
.arc-stats__reasons li { padding: 8px 0; border-bottom: 1px solid var(--ee-border); }
.arc-stats__reasons li:last-child { border-bottom: 0; }
.arc-stats__reason { display: flex; justify-content: space-between; gap: 12px; color: var(--ee-text-primary); }
.arc-stats__reason .num { font-weight: 600; font-variant-numeric: tabular-nums; }
.arc-stats__examples { margin-top: 2px; font-size: 0.85em; color: var(--ee-text-secondary); overflow-wrap: anywhere; }
</style>
