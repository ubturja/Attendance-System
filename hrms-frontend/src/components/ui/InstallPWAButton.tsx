import { Download } from 'lucide-react';
import usePWAInstall from '../../hooks/usePWAInstall';
import { Button } from './Button';

export function InstallPWAButton() {
  const { isInstallable, promptInstall } = usePWAInstall();

  if (!isInstallable) {
    return null;
  }

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      onClick={promptInstall}
      className="text-brand-600 hover:bg-slate-50 hover:text-brand-600"
    >
      <Download className="h-4 w-4" aria-hidden="true" />
      Install App
    </Button>
  );
}
