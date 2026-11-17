import {
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Badge, Card,
} from '@fluentui/react-components';

export type FinanceRow = Record<string, string>;

export function FinanceTable({ columns, rows }: { columns: string[]; rows: FinanceRow[] }) {
  return <Card className="table-card">
    <Table aria-label="Finance records">
      <TableHeader><TableRow>{columns.map((column) => <TableHeaderCell key={column}>{column}</TableHeaderCell>)}</TableRow></TableHeader>
      <TableBody>{rows.map((row, index) => <TableRow key={`${Object.values(row)[0]}-${index}`}>
        {columns.map((column, cellIndex) => <TableCell key={column}>
          {cellIndex === columns.length - 1 && (column === 'Status' || column === 'Type' || column === 'Tipo')
            ? <Badge appearance="tint" color={(row[column] ?? '').toLowerCase().includes('due') || (row[column] ?? '').toLowerCase().includes('deuda') ? 'warning' : 'success'}>{row[column] ?? '—'}</Badge>
            : (row[column] ?? '—')}
        </TableCell>)}
      </TableRow>)}</TableBody>
    </Table>
  </Card>;
}
