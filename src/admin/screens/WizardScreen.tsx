import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	ArrowLeftIcon,
	ArrowRightIcon,
	CheckIcon,
	FileCodeIcon,
	LoaderCircleIcon,
	PaletteIcon,
	PartyPopperIcon,
	ZapIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { LogoMark } from '@/admin/components/shell/PageHeader';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Eyebrow } from '@/admin/components/shell/Signals';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { cn } from '@/admin/lib/utils';
import { interpolate } from '@/admin/lib/interpolate';
import { navigate } from '@/admin/lib/navigate';

type Style = 'vanilla' | 'tailwind';

/**
 * GET admin/wizard (includes/API/Admin/WizardRoutes.php). The payload also
 * carries `demos` and `installPath` for API stability; demo installation is
 * not exposed in the UI since 2.15, so they are not read.
 */
export interface WizardData {
	style: Style;
	categoryName: string;
	tailwindEnabled: boolean;
	completed: boolean;
}

const CATEGORY_NAME_MAX = 100;
const DEFAULT_CATEGORY = 'Proto Blocks';
const STEPS = [1, 2, 3] as const;
type StepNumber = (typeof STEPS)[number];

function stepLabel(step: StepNumber): string {
	switch (step) {
		case 1:
			return __('Welcome', 'proto-blocks');
		case 2:
			return __('Styling', 'proto-blocks');
		default:
			return __('Finish', 'proto-blocks');
	}
}

function stepHint(step: StepNumber): string {
	switch (step) {
		case 1:
			return __('What Proto-Blocks does', 'proto-blocks');
		case 2:
			return __('Vanilla CSS or Tailwind', 'proto-blocks');
		default:
			return __('Review and go', 'proto-blocks');
	}
}

function styleLabel(style: Style): string {
	return style === 'tailwind' ? __('Tailwind CSS', 'proto-blocks') : __('Vanilla CSS', 'proto-blocks');
}

function StepIndicator({ current }: { current: StepNumber }) {
	return (
		<ol className="grid gap-1" aria-label={__('Setup progress', 'proto-blocks')}>
			{STEPS.map((step) => {
				const done = step < current;
				const active = step === current;
				return (
					<li
						key={step}
						aria-current={active ? 'step' : undefined}
						className={cn(
							'flex items-center gap-3 rounded-lg px-3 py-2.5',
							active ? 'bg-white/[.06] text-panel-foreground' : 'text-faint'
						)}
					>
						<span
							aria-hidden="true"
							className={cn(
								'grid size-6 flex-none place-items-center rounded-full text-micro tabular-nums',
								active
									? 'bg-brand font-semibold text-panel'
									: done
										? 'bg-brand/20 text-brand'
										: 'border border-panel-line'
							)}
						>
							{done ? <CheckIcon className="size-3.5" strokeWidth={2.5} /> : step}
						</span>
						<span className="grid">
							<span className={cn('font-medium', active ? 'text-panel-foreground' : 'text-input')}>
								{stepLabel(step)}
							</span>
							<span className="text-micro text-faint">{stepHint(step)}</span>
						</span>
					</li>
				);
			})}
		</ol>
	);
}

/** Step title: the screen's h1, focused when the step changes. */
function StepTitle({ children, className }: { children: ReactNode; className?: string }) {
	const ref = useRef<HTMLHeadingElement>(null);
	useEffect(() => {
		ref.current?.focus({ preventScroll: true });
	}, []);
	return (
		<h1
			ref={ref}
			tabIndex={-1}
			className={cn('text-[28px] leading-[1.15] font-semibold tracking-[-0.02em] text-balance outline-none', className)}
		>
			{children}
		</h1>
	);
}

/** "Step 1 of 3" overline above each step title. */
function StepKicker({ step }: { step: StepNumber }) {
	return (
		<Eyebrow tone="brand">
			{sprintf(
				/* translators: 1: current step number, 2: number of steps. */
				__('Step %1$d of %2$d', 'proto-blocks'),
				step,
				STEPS.length
			)}
		</Eyebrow>
	);
}

const mono = (text: ReactNode) => <code className="font-mono text-[13px] text-link">{text}</code>;

function Welcome({ onNext, onSkip, skipping }: { onNext: () => void; onSkip: () => void; skipping: boolean }) {
	const points = [
		{
			icon: <FileCodeIcon aria-hidden="true" />,
			title: __('PHP templates', 'proto-blocks'),
			text: __('Familiar PHP and HTML; fields bind to markup with data attributes.', 'proto-blocks'),
		},
		{
			icon: <ZapIcon aria-hidden="true" />,
			title: __('Live as soon as the files exist', 'proto-blocks'),
			text: __('Templates compile and cache on first render.', 'proto-blocks'),
		},
		{
			icon: <PaletteIcon aria-hidden="true" />,
			title: __('Vanilla CSS or Tailwind', 'proto-blocks'),
			text: __('Tailwind compiles in the browser or via CLI — no Node on the server.', 'proto-blocks'),
		},
	];

	return (
		<div className="grid content-start gap-7">
			<div className="grid gap-2.5">
				<StepKicker step={1} />
				<StepTitle>{__('Gutenberg blocks from PHP templates.', 'proto-blocks')}</StepTitle>
				<p className="max-w-[52ch] text-muted-foreground">
					{interpolate(
						/* translators: 1: block.json, 2: template.php */
						__("No React, no build step. Describe a block in %1$s, write its markup in %2$s, and it's in the inserter.", 'proto-blocks'),
						mono('block.json'),
						mono('template.php')
					)}
				</p>
			</div>
			<ul className="grid gap-3.5">
				{points.map((point) => (
					<li key={point.title} className="flex gap-3.5">
						<div className="grid size-9 flex-none place-items-center rounded-lg bg-brand-soft text-brand-ink [&_svg]:size-4">
							{point.icon}
						</div>
						<div>
							<p className="font-medium">{point.title}</p>
							<p className="text-label text-muted-foreground">{point.text}</p>
						</div>
					</li>
				))}
			</ul>
			<div className="flex items-center justify-between pt-2">
				<Button variant="ghost" className="-ml-2.5 px-2.5 text-label text-muted-foreground" disabled={skipping} onClick={onSkip}>
					{__('Skip setup', 'proto-blocks')}
				</Button>
				<Button size="lg" onClick={onNext}>
					{__('Get started', 'proto-blocks')}
					<ArrowRightIcon aria-hidden="true" />
				</Button>
			</div>
		</div>
	);
}

function ChooseStyle({
	style,
	categoryName,
	saving,
	onStyle,
	onCategory,
	onBack,
	onNext,
}: {
	style: Style;
	categoryName: string;
	saving: boolean;
	onStyle: (style: Style) => void;
	onCategory: (name: string) => void;
	onBack: () => void;
	onNext: () => void;
}) {
	const tooLong = categoryName.length > CATEGORY_NAME_MAX;
	const options: { value: Style; label: string; help: string; badge?: string }[] = [
		{
			value: 'vanilla',
			label: __('Vanilla CSS', 'proto-blocks'),
			help: __('Plain stylesheets that live next to each block.', 'proto-blocks'),
			badge: __('Recommended for beginners', 'proto-blocks'),
		},
		{
			value: 'tailwind',
			label: __('Tailwind CSS', 'proto-blocks'),
			help: __('Utility classes, compiled by the plugin.', 'proto-blocks'),
		},
	];

	return (
		<>
			<div className="grid gap-2.5">
				<StepKicker step={2} />
				<StepTitle>{__('Choose your style', 'proto-blocks')}</StepTitle>
			</div>
			<p className="mt-2.5 text-muted-foreground">
				{__('How your blocks are styled. You can change this later.', 'proto-blocks')}
			</p>
			<RadioGroup
				value={style}
				onValueChange={(value) => onStyle(value as Style)}
				aria-label={__('Styling approach', 'proto-blocks')}
				className="mt-5 gap-3 sm:grid-cols-2"
			>
				{options.map((option) => {
					const id = `pb-wizard-style-${option.value}`;
					return (
						<label
							key={option.value}
							htmlFor={id}
							className={cn(
								'flex cursor-pointer flex-col gap-2 rounded-lg border bg-background p-3.5 transition-colors hover:border-input',
								style === option.value && 'border-primary bg-code shadow-[inset_0_0_0_1px_var(--primary)] hover:border-primary'
							)}
						>
							<span className="flex w-full items-center gap-3">
								<RadioGroupItem id={id} value={option.value} />
								<span className="text-sm font-medium">{option.label}</span>
							</span>
							{option.badge ? (
								<Badge variant="brand" className="ml-7 h-auto w-fit whitespace-normal">
									{option.badge}
								</Badge>
							) : null}
							<span className="pl-7 text-caption text-muted-foreground">{option.help}</span>
						</label>
					);
				})}
			</RadioGroup>
			<div className="mt-6 grid gap-2">
				<Label htmlFor="pb-wizard-category">
					{__('Block category name', 'proto-blocks')}{' '}
					<span className="font-normal text-muted-foreground">{__('(optional)', 'proto-blocks')}</span>
				</Label>
				<Input
					id="pb-wizard-category"
					className="max-w-[420px]"
					placeholder={DEFAULT_CATEGORY}
					value={categoryName}
					onChange={(event) => onCategory(event.target.value)}
					aria-invalid={tooLong ? true : undefined}
					aria-describedby={tooLong ? 'pb-wizard-category-error pb-wizard-category-hint' : 'pb-wizard-category-hint'}
				/>
				{tooLong ? (
					<p id="pb-wizard-category-error" className="text-label text-destructive">
						{__('Use 100 characters or fewer.', 'proto-blocks')}
					</p>
				) : null}
				<p id="pb-wizard-category-hint" className="text-label text-muted-foreground">
					{__('The heading your blocks sit under in the block inserter.', 'proto-blocks')}
				</p>
			</div>
			<div className="mt-8 flex justify-between">
				<Button variant="ghost" className="-ml-2.5 px-2.5 text-muted-foreground" onClick={onBack} disabled={saving}>
					<ArrowLeftIcon aria-hidden="true" />
					{__('Back', 'proto-blocks')}
				</Button>
				<Button size="lg" onClick={onNext} disabled={saving || tooLong}>
					{saving ? (
						<>
							<LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
							{__('Saving…', 'proto-blocks')}
						</>
					) : (
						<>
							{__('Continue', 'proto-blocks')}
							<ArrowRightIcon aria-hidden="true" />
						</>
					)}
				</Button>
			</div>
		</>
	);
}

function Finish({
	data,
	completing,
	onComplete,
}: {
	data: WizardData;
	completing: boolean;
	onComplete: () => void;
}) {
	const category = data.categoryName.trim() === '' ? DEFAULT_CATEGORY : data.categoryName;
	const rows: { label: string; value: ReactNode }[] = [
		{ label: __('Styling Approach', 'proto-blocks'), value: styleLabel(data.style) },
		{ label: __('Category Name', 'proto-blocks'), value: category },
	];
	if (data.style === 'tailwind') {
		rows.push({
			label: __('Tailwind Integration', 'proto-blocks'),
			value: data.tailwindEnabled ? (
				<Badge variant="success">{__('Enabled', 'proto-blocks')}</Badge>
			) : (
				<Badge variant="dashed">{__('Disabled', 'proto-blocks')}</Badge>
			),
		});
	}

	return (
		<>
			<div className="grid gap-2.5">
				<StepKicker step={3} />
				<div className="flex items-center gap-3">
					<div
						aria-hidden="true"
						className="grid size-9 flex-none place-items-center rounded-lg bg-success-soft text-success [&_svg]:size-4"
					>
						<PartyPopperIcon />
					</div>
					<StepTitle>{__('Proto Blocks is ready', 'proto-blocks')}</StepTitle>
				</div>
			</div>
			<h2 className="mt-7 mb-2 text-label font-medium">{__('Setup Summary', 'proto-blocks')}</h2>
			<dl className="divide-y divide-divider rounded-lg border">
				{rows.map((row) => (
					<div key={row.label} className="flex items-baseline justify-between gap-4 px-4 py-3">
						<dt className="text-label text-muted-foreground">{row.label}</dt>
						<dd className="font-medium">{row.value}</dd>
					</div>
				))}
			</dl>
			{data.style === 'vanilla' && data.tailwindEnabled ? (
				<p className="mt-3 text-label text-muted-foreground">
					{__('Tailwind stays enabled; turn it off in Tailwind Settings if you no longer need it.', 'proto-blocks')}
				</p>
			) : null}
			<div className="mt-8 flex justify-end">
				<Button size="lg" onClick={onComplete} disabled={completing}>
					{completing ? (
						<LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
					) : null}
					{__('Go to Dashboard', 'proto-blocks')}
					<ArrowRightIcon aria-hidden="true" />
				</Button>
			</div>
		</>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="mx-auto grid max-w-[960px] pt-12 md:grid-cols-[320px_minmax(0,1fr)]">
			<Skeleton className="h-[420px] rounded-3xl md:rounded-r-none" />
			<div className="grid content-start gap-3 rounded-r-3xl border bg-card p-11">
				<Skeleton className="h-3 w-24" />
				<Skeleton className="h-8 w-80" />
				<Skeleton className="h-4 w-full" />
				<Skeleton className="h-24 w-full" />
			</div>
		</div>
	);
}

type LoadState = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; data: WizardData };

const errorMessage = (error: unknown): string | undefined => (error instanceof Error ? error.message : undefined);

export default function WizardScreen() {
	const [state, setState] = useState<LoadState>({ status: 'loading' });
	const [step, setStep] = useState<StepNumber>(1);
	const [style, setStyle] = useState<Style>('vanilla');
	const [categoryName, setCategoryName] = useState('');
	const [saving, setSaving] = useState(false);
	const [completing, setCompleting] = useState(false);

	const load = useCallback(() => {
		setState({ status: 'loading' });
		adminGet<WizardData>('admin/wizard').then(
			(data) => {
				if (data) {
					setStyle(data.style);
					setCategoryName(data.categoryName);
					setState({ status: 'ready', data });
				}
			},
			(error: unknown) => setState({ status: 'error', error })
		);
	}, []);

	useEffect(load, [load]);

	const saveStyle = async () => {
		setSaving(true);
		try {
			const data = await adminPost<WizardData>('admin/wizard/style', { style, categoryName: categoryName.trim() });
			setState({ status: 'ready', data });
			setStep(3);
		} catch (error) {
			toast.error(__("Couldn't save your choices.", 'proto-blocks'), { description: errorMessage(error) });
		} finally {
			setSaving(false);
		}
	};

	const complete = async () => {
		setCompleting(true);
		try {
			const { redirect } = await adminPost<{ redirect: string }>('admin/wizard/complete');
			navigate(redirect);
		} catch (error) {
			setCompleting(false);
			toast.error(__("Couldn't finish the setup.", 'proto-blocks'), { description: errorMessage(error) });
		}
	};

	if (state.status === 'loading') {
		return <LoadingState />;
	}
	if (state.status === 'error') {
		return (
			<ScreenError
				screen={__('Setup Wizard', 'proto-blocks')}
				error={state.error instanceof ApiError ? state.error : undefined}
				onRetry={load}
			/>
		);
	}

	const { data } = state;
	let body: ReactNode;
	switch (step) {
		case 1:
			body = <Welcome onNext={() => setStep(2)} onSkip={complete} skipping={completing} />;
			break;
		case 2:
			body = (
				<ChooseStyle
					style={style}
					categoryName={categoryName}
					saving={saving}
					onStyle={setStyle}
					onCategory={setCategoryName}
					onBack={() => setStep(1)}
					onNext={saveStyle}
				/>
			);
			break;
		default:
			body = (
				<Finish
					data={data}
					completing={completing}
					onComplete={complete}
				/>
			);
	}

	return (
		<div className="pt-12 pb-[72px]">
			<div className="mx-auto grid max-w-[960px] overflow-hidden rounded-3xl border bg-card md:grid-cols-[320px_minmax(0,1fr)]">
				<div className="flex flex-col gap-8 bg-panel px-7 py-8 text-panel-foreground">
					<div className="flex items-center gap-3">
						<LogoMark size={36} />
						<div className="grid">
							<span className="font-semibold">{__('Proto-Blocks', 'proto-blocks')}</span>
							<span className="font-mono text-[11px] text-faint">{__('Setup · about a minute', 'proto-blocks')}</span>
						</div>
					</div>
					<StepIndicator current={step} />
					<pre aria-hidden="true" className="mt-auto hidden font-mono text-micro leading-[1.7] text-faint md:block">
						<span className="text-input">your-theme/</span>
						{'proto-blocks/\n  '}
						<span className="text-brand">hero/</span>
						{'\n    block.json\n    template.php'}
					</pre>
				</div>
				{/* key remounts the step so its title takes focus */}
				<div key={step} className="min-w-0 px-6 py-8 md:px-11 md:py-10">
					{body}
				</div>
			</div>
		</div>
	);
}
