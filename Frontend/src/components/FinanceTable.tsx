import type { ReactNode } from 'react';
import {
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Badge, Card,
} from '@fluentui/react-components';

export type FinanceRow = Record<string, ReactNode>;

export function FinanceTable({ columns, rows }: { columns: string[]; rows: FinanceRow[] }) {
  return <Card className="table-card">
    <Table aria-label="Finance records">
      <TableHeader><TableRow>{columns.map((column) => <TableHeaderCell key={column}>{column}</TableHeaderCell>)}</TableRow></TableHeader>
      <TableBody>{rows.map((row, index) => <TableRow key={`${String(Object.values(row)[0])}-${index}`}>
        {columns.map((column, cellIndex) => {
          const val = row[column];
          const isString = typeof val === 'string';
          return (
            <TableCell key={column}>
              {cellIndex === columns.length - 1 && isString && (column === 'Status' || column === 'Type' || column === 'Tipo')
                ? <Badge appearance="tint" color={val.toLowerCase().includes('due') || val.toLowerCase().includes('deuda') ? 'warning' : 'success'}>{val}</Badge>
                : (val ?? '—')}
            </TableCell>
          );
        })}</TableRow>)}</TableBody>
    </Table>
  </Card>;
}
