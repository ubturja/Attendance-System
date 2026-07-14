import { TableCell, TableRow } from './Table';

export interface TableErrorRowProps {
  colSpan: number;
  message?: string;
}

export function TableErrorRow({
  colSpan,
  message = 'Failed to load data. Please try again.',
}: TableErrorRowProps) {
  return (
    <TableRow className="hover:bg-transparent">
      <TableCell colSpan={colSpan} className="py-8 text-center text-sm text-red-600">
        {message}
      </TableCell>
    </TableRow>
  );
}
