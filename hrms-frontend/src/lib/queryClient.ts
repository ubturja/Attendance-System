import { QueryClient } from '@tanstack/react-query';

/** Shared TanStack Query client — cleared on logout to prevent cross-session cache leaks. */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});
