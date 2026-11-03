import { render, screen } from '@testing-library/react';
import { Button } from '@/admin/components/ui/button';

test('shadcn Button renders with variant classes', () => {
  render(<Button variant="destructive">Remove</Button>);
  const b = screen.getByRole('button', { name: 'Remove' });
  expect(b.className).toContain('bg-destructive');
});
