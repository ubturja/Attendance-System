import { useSearchParams } from 'react-router-dom';
import { cn } from '../../lib/utils';
import DailyReport from './DailyReport';
import MonthlyReport from './MonthlyReport';
import YearlyReport from './YearlyReport';

type ReportTab = 'daily' | 'monthly' | 'yearly';

const REPORT_TABS: { id: ReportTab; label: string }[] = [
  { id: 'daily', label: 'Daily' },
  { id: 'monthly', label: 'Monthly' },
  { id: 'yearly', label: 'Yearly' },
];

function resolveReportTab(value: string | null): ReportTab {
  if (value === 'monthly' || value === 'yearly' || value === 'daily') {
    return value;
  }

  return 'daily';
}

export default function Reports() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = resolveReportTab(searchParams.get('tab'));

  function handleTabChange(nextTab: ReportTab): void {
    // Reset filter params when switching report types — each tab owns its own filters.
    setSearchParams({ tab: nextTab });
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-800">Reports</h1>
        <p className="mt-1 text-sm text-slate-500">
          Daily attendance, monthly matrices, and yearly leave balances.
        </p>
      </div>

      <div
        className="inline-flex overflow-hidden rounded-md border border-slate-300"
        role="tablist"
        aria-label="Report type"
      >
        {REPORT_TABS.map((item, index) => {
          const isActive = tab === item.id;

          return (
            <button
              key={item.id}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => handleTabChange(item.id)}
              className={cn(
                'h-10 px-4 text-sm font-medium transition-colors',
                index > 0 && 'border-l border-slate-300',
                isActive
                  ? 'bg-brand text-white'
                  : 'bg-white text-slate-600 hover:bg-brand-50 hover:text-brand',
              )}
            >
              {item.label}
            </button>
          );
        })}
      </div>

      <div role="tabpanel">
        {tab === 'daily' ? <DailyReport /> : null}
        {tab === 'monthly' ? <MonthlyReport /> : null}
        {tab === 'yearly' ? <YearlyReport /> : null}
      </div>
    </div>
  );
}
