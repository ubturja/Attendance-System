import { useQuery } from '@tanstack/react-query';
import { getHolidays } from '../lib/api';
import { queryKeys } from '../lib/queryKeys';

/** Active holidays list (`GET /holidays`), shared by Calendar and Daily Report. */
export function useHolidays() {
  return useQuery({
    queryKey: queryKeys.holidays.all,
    queryFn: () => getHolidays(),
  });
}
