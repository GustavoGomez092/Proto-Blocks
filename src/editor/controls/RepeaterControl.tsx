/**
 * Repeater control
 *
 * A repeatable group of controls held in the sidebar: an ordered list of rows,
 * each built from the control's own `fields`.
 *
 * The repeater FIELD already covers repeated *content* — it renders its editing
 * UI into the canvas, beside the thing being edited, which is right when the
 * author is looking at what they are changing. It is wrong when the repeated
 * thing is configuration: a set of tabs, a list of breakpoints, a table pasted
 * as CSV. Those have no natural place on the canvas, and putting them there
 * means the block's own markup carries editing furniture.
 *
 * Rows do not nest. A second level inside a sidebar panel is unreadable, and
 * keeping it flat keeps the stored value a plain list of flat objects.
 */

import React from 'react';
import { useState } from '@wordpress/element';
import { Button, BaseControl, Icon } from '@wordpress/components';
import { chevronDown, chevronUp, trash, plus } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { ControlConfig } from '../types';

export interface RepeaterRow {
	[key: string]: unknown;
}

interface RepeaterControlProps {
	label?: string;
	help?: string;
	/** One row's controls, keyed by the name each value is stored under. */
	fields: Record<string, ControlConfig>;
	/** Which field titles a row in the list. */
	itemLabel?: string;
	min?: number;
	max?: number;
	value?: RepeaterRow[];
	/** Renders one of the row's controls; injected so this file stays free of
	    the control switch and cannot import it circularly. */
	renderField: (
		name: string,
		config: ControlConfig,
		value: unknown,
		onChange: (next: unknown) => void
	) => React.ReactNode;
	onChange: (rows: RepeaterRow[]) => void;
}

export function RepeaterControl({
	label,
	help,
	fields,
	itemLabel,
	min = 0,
	max,
	value,
	renderField,
	onChange,
}: RepeaterControlProps): JSX.Element {
	const rows: RepeaterRow[] = Array.isArray(value) ? value : [];
	const [open, setOpen] = useState<number | null>(rows.length ? 0 : null);

	const names = Object.keys(fields || {});
	const titleField = itemLabel && fields[itemLabel] ? itemLabel : names[0];

	const emptyRow = (): RepeaterRow => {
		const row: RepeaterRow = {};

		names.forEach((name) => {
			const def = fields[name]?.default;

			if (def !== undefined) {
				row[name] = def;
			}
		});

		return row;
	};

	const write = (next: RepeaterRow[]) => onChange(next);

	const update = (index: number, name: string, next: unknown) => {
		const copy = rows.map((row, i) => (i === index ? { ...row, [name]: next } : row));

		write(copy);
	};

	const add = () => {
		if (max !== undefined && rows.length >= max) {
			return;
		}

		write([...rows, emptyRow()]);
		setOpen(rows.length);
	};

	const remove = (index: number) => {
		if (rows.length <= min) {
			return;
		}

		write(rows.filter((_, i) => i !== index));
		setOpen(null);
	};

	/** Move a row one place, which is how the order is edited. */
	const move = (index: number, by: number) => {
		const to = index + by;

		if (to < 0 || to >= rows.length) {
			return;
		}

		const copy = [...rows];

		[copy[index], copy[to]] = [copy[to], copy[index]];

		write(copy);
		setOpen(to);
	};

	const rowTitle = (row: RepeaterRow, index: number): string => {
		const raw = titleField ? row[titleField] : '';
		const text = typeof raw === 'string' ? raw.trim() : '';

		return text !== ''
			? text
			: sprintf(
					/* translators: %d is the row's position in the list. */
					__('Item %d', 'proto-blocks'),
					index + 1
			  );
	};

	return (
		<BaseControl __nextHasNoMarginBottom label={label} help={help}>
			<div className="proto-blocks-repeater">
				{rows.length === 0 && (
					<p className="proto-blocks-repeater__empty">
						{__('Nothing here yet.', 'proto-blocks')}
					</p>
				)}

				{rows.map((row, index) => {
					const isOpen = open === index;

					return (
						<div
							key={index}
							className={`proto-blocks-repeater__row${
								isOpen ? ' is-open' : ''
							}`}
						>
							<div className="proto-blocks-repeater__header">
								<button
									type="button"
									className="proto-blocks-repeater__toggle"
									aria-expanded={isOpen}
									onClick={() => setOpen(isOpen ? null : index)}
								>
									<Icon icon={isOpen ? chevronUp : chevronDown} />
									<span>{rowTitle(row, index)}</span>
								</button>

								<div className="proto-blocks-repeater__actions">
									<Button
										size="small"
										icon={chevronUp}
										label={__('Move up', 'proto-blocks')}
										disabled={index === 0}
										onClick={() => move(index, -1)}
									/>
									<Button
										size="small"
										icon={chevronDown}
										label={__('Move down', 'proto-blocks')}
										disabled={index === rows.length - 1}
										onClick={() => move(index, 1)}
									/>
									<Button
										size="small"
										icon={trash}
										isDestructive
										label={__('Remove', 'proto-blocks')}
										disabled={rows.length <= min}
										onClick={() => remove(index)}
									/>
								</div>
							</div>

							{isOpen && (
								<div className="proto-blocks-repeater__body">
									{names.map((name) =>
										<div key={name} className="proto-blocks-repeater__field">
											{renderField(
												name,
												fields[name],
												row[name],
												(next) => update(index, name, next)
											)}
										</div>
									)}
								</div>
							)}
						</div>
					);
				})}

				<Button
					variant="secondary"
					icon={plus}
					className="proto-blocks-repeater__add"
					disabled={max !== undefined && rows.length >= max}
					onClick={add}
				>
					{__('Add item', 'proto-blocks')}
				</Button>
			</div>
		</BaseControl>
	);
}
