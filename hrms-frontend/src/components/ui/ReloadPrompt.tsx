import { useRegisterSW } from 'virtual:pwa-register/react';
import { Button } from './Button';

export function ReloadPrompt() {
  const {
    offlineReady: [offlineReady, setOfflineReady],
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegistered() {
      console.log('SW Registered');
    },
    onRegisterError(error) {
      console.log('SW registration error', error);
    },
  });

  if (!offlineReady && !needRefresh) {
    return null;
  }

  const close = () => {
    setOfflineReady(false);
    setNeedRefresh(false);
  };

  return (
    <div className="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-slate-200 bg-white p-4 shadow-lg">
      <p className="text-sm text-slate-700">
        {needRefresh
          ? 'New update available!'
          : 'App ready to work offline.'}
      </p>
      <div className="mt-3 flex justify-end gap-2">
        {needRefresh ? (
          <Button size="sm" onClick={() => updateServiceWorker(true)}>
            Reload
          </Button>
        ) : null}
        <Button size="sm" variant="outline" onClick={close}>
          Close
        </Button>
      </div>
    </div>
  );
}
