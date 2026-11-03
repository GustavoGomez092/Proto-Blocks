import { useId, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { FileCheck2Icon, FilePlus2Icon, LayoutTemplateIcon } from 'lucide-react';
import { toast } from 'sonner';
import { adminPost, ApiError } from '@/admin/api';
import { Button } from '@/admin/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/admin/components/ui/card';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Label } from '@/admin/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/admin/components/ui/tooltip';

export interface TemplateTarget {
	name: string;
	exists: boolean;
}

function count(value: unknown): number {
	if (Array.isArray(value)) {
		return value.length;
	}
	return typeof value === 'number' ? value : 0;
}

/**
 * Starter block templates for the type: which exist, which are missing, and
 * a Scaffold button. Existing files are never overwritten (the server skips
 * them). Off on classic themes and in read-only mode.
 */
export function TemplatesCard({
	slug,
	templates,
	blockTheme,
	readOnly,
	onScaffolded,
}: {
	slug: string;
	templates: TemplateTarget[];
	blockTheme: boolean;
	readOnly: boolean;
	onScaffolded: () => void;
}) {
	const [taxonomies, setTaxonomies] = useState(false);
	const [busy, setBusy] = useState(false);
	const checkboxId = useId();
	const helpId = useId();
	const classic = __('Classic theme: build templates in your theme', 'proto-blocks');
	const disabled = !blockTheme || readOnly || busy;

	const scaffold = async () => {
		setBusy(true);
		try {
			const result = await adminPost<{ written: unknown; skipped: unknown }>(`admin/types/${slug}/scaffold`, { taxonomies });
			const written = count(result?.written);
			const skipped = count(result?.skipped);
			toast.success(
				sprintf(
					/* translators: 1: number of templates written, 2: number skipped because they exist. */
					_n('Wrote %1$d template, skipped %2$d.', 'Wrote %1$d templates, skipped %2$d.', written, 'proto-blocks'),
					written,
					skipped
				)
			);
			onScaffolded();
		} catch (e) {
			toast.error(e instanceof ApiError ? e.message : __('The templates could not be written.', 'proto-blocks'));
		} finally {
			setBusy(false);
		}
	};

	const button = (
		<Button
			type="button"
			variant="outline"
			disabled={disabled}
			aria-describedby={!blockTheme ? helpId : undefined}
			onClick={scaffold}
		>
			<LayoutTemplateIcon aria-hidden="true" />
			{__('Scaffold templates', 'proto-blocks')}
		</Button>
	);

	return (
		<Card className="shadow-xs" id="pt-templates">
			<CardHeader>
				<CardTitle role="heading" aria-level={2}>{__('Templates', 'proto-blocks')}</CardTitle>
				<CardDescription>
					{__('Create starter block templates in your theme. Existing files are never overwritten.', 'proto-blocks')}
				</CardDescription>
			</CardHeader>
			<CardContent className="grid gap-4">
				{templates.length > 0 ? (
					<ul className="grid gap-1.5 text-sm">
						{templates.map((template) => (
							<li key={template.name} className="flex items-center gap-2">
								{template.exists ? (
									<FileCheck2Icon aria-hidden="true" className="size-4 text-success" />
								) : (
									<FilePlus2Icon aria-hidden="true" className="size-4 text-muted-foreground" />
								)}
								<code className="font-mono text-[12.5px]">{template.name}</code>
								<span className="text-[13px] text-muted-foreground">
									{template.exists ? __('exists · will skip', 'proto-blocks') : __('missing', 'proto-blocks')}
								</span>
							</li>
						))}
					</ul>
				) : null}
				<div className="flex items-center gap-2">
					<Checkbox
						id={checkboxId}
						checked={taxonomies}
						disabled={disabled}
						onCheckedChange={(next) => setTaxonomies(next === true)}
					/>
					<Label htmlFor={checkboxId} className="text-sm font-normal">
						{__('Also taxonomy templates', 'proto-blocks')}
					</Label>
				</div>
				<div className="flex flex-wrap items-center gap-3">
					{!blockTheme ? (
						<Tooltip>
							<TooltipTrigger asChild>
								{/* A disabled button gets no pointer events, so the wrapper carries the tooltip. */}
								<span tabIndex={0} className="inline-flex rounded-md">
									{button}
								</span>
							</TooltipTrigger>
							<TooltipContent>{classic}</TooltipContent>
						</Tooltip>
					) : (
						button
					)}
					{!blockTheme ? (
						<p id={helpId} className="text-[13px] text-muted-foreground">
							{classic}
						</p>
					) : null}
				</div>
			</CardContent>
		</Card>
	);
}
