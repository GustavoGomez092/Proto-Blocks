import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
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
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { cn } from '@/admin/lib/utils';
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
			return __('Style', 'proto-blocks');
		default:
			return __('Finish', 'proto-blocks');
	}
}

function styleLabel(style: Style): string {
	return style === 'tailwind' ? __('Tailwind CSS', 'proto-blocks') : __('Vanilla CSS', 'proto-blocks');
}

function StepIndicator({ current }: { current: StepNumber }) {
	return (
		<ol className="mb-6 flex flex-wrap items-center gap-2" aria-label={__('Setup progress', 'proto-blocks')}>
			{STEPS.map((step) => {
				const done = step < current;
				const active = step === current;
				return (
					<li
						key={step}
						aria-current={active ? 'step' : undefined}
						className={cn(
							'flex items-center gap-2 text-[13px]',
							active ? 'font-semibold text-foreground' : done ? 'text-foreground' : 'text-muted-foreground'
						)}
					>
						<span
							aria-hidden="true"
							className={cn(
								'grid size-6 place-items-center rounded-full text-xs tabular-nums',
								done || active ? 'bg-primary text-primary-foreground' : 'border bg-card text-muted-foreground'
							)}
						>
							{done ? <CheckIcon className="size-3.5" /> : step}
						</span>
						{stepLabel(step)}
						{step < STEPS.length ? <span aria-hidden="true" className="mr-1 h-px w-6 bg-border" /> : null}
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
			className={cn('text-[22px] leading-tight font-semibold tracking-tight outline-none', className)}
		>
			{children}
		</h1>
	);
}

function Welcome({ onNext }: { onNext: () => void }) {
	const points = [
		{
			icon: <FileCodeIcon aria-hidden="true" />,
			title: __('PHP templates', 'proto-blocks'),
			text: __('Write blocks with familiar PHP and HTML.', 'proto-blocks'),
		},
		{
			icon: <ZapIcon aria-hidden="true" />,
			title: __('No build step', 'proto-blocks'),
			text: __('Blocks work as soon as the files are in your theme.', 'proto-blocks'),
		},
		{
			icon: <PaletteIcon aria-hidden="true" />,
			title: __('Flexible styling', 'proto-blocks'),
			text: __('Use vanilla CSS or Tailwind.', 'proto-blocks'),
		},
	];

	return (
		<>
			<StepTitle>{__('Welcome to Proto Blocks', 'proto-blocks')}</StepTitle>
			<p className="mt-2 max-w-[56ch] text-sm text-muted-foreground">
				{__(
					'Build Gutenberg blocks from PHP templates instead of React. This takes about a minute, and you can change everything later.',
					'proto-blocks'
				)}
			</p>
			<ul className="mt-6 grid gap-3">
				{points.map((point) => (
					<li key={point.title} className="flex gap-3">
						<div className="grid size-9 flex-none place-items-center rounded-lg bg-accent text-primary [&_svg]:size-4">
							{point.icon}
						</div>
						<div>
							<p className="text-sm font-medium">{point.title}</p>
							<p className="text-[13px] text-muted-foreground">{point.text}</p>
						</div>
					</li>
				))}
			</ul>
			<div className="mt-8 flex justify-end">
				<Button onClick={onNext}>
					{__('Get Started', 'proto-blocks')}
					<ArrowRightIcon aria-hidden="true" />
				</Button>
			</div>
		</>
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
			<StepTitle className="text-[18px]">{__('Choose your style', 'proto-blocks')}</StepTitle>
			<p className="mt-1 text-[13px] text-muted-foreground">
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
								'flex cursor-pointer flex-col gap-2 rounded-lg border p-4 transition-colors hover:bg-accent/40',
								style === option.value && 'border-primary bg-accent/40'
							)}
						>
							<span className="flex w-full items-center gap-3">
								<RadioGroupItem id={id} value={option.value} />
								<span className="text-sm font-medium">{option.label}</span>
							</span>
							{option.badge ? (
								<Badge variant="secondary" className="ml-7 h-auto w-fit rounded-sm whitespace-normal">
									{option.badge}
								</Badge>
							) : null}
							<span className="pl-7 text-[13px] text-muted-foreground">{option.help}</span>
						</label>
					);
				})}
			</RadioGroup>
			<div className="mt-6 grid gap-2">
				<Label htmlFor="pb-wizard-category" className="text-sm font-medium">
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
					<p id="pb-wizard-category-error" className="text-[13px] text-destructive">
						{__('Use 100 characters or fewer.', 'proto-blocks')}
					</p>
				) : null}
				<p id="pb-wizard-category-hint" className="text-[13px] text-muted-foreground">
					{__('The heading your blocks sit under in the block inserter.', 'proto-blocks')}
				</p>
			</div>
			<div className="mt-8 flex justify-between">
				<Button variant="ghost" onClick={onBack} disabled={saving}>
					<ArrowLeftIcon aria-hidden="true" />
					{__('Back', 'proto-blocks')}
				</Button>
				<Button onClick={onNext} disabled={saving || tooLong}>
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
				<Badge variant="outline" className="rounded-sm border-transparent bg-success-soft text-success">
					{__('Enabled', 'proto-blocks')}
				</Badge>
			) : (
				<Badge variant="outline" className="rounded-sm">
					{__('Disabled', 'proto-blocks')}
				</Badge>
			),
		});
	}

	return (
		<>
			<div className="flex items-center gap-3">
				<div
					aria-hidden="true"
					className="grid size-9 flex-none place-items-center rounded-lg bg-success-soft text-success [&_svg]:size-4"
				>
					<PartyPopperIcon />
				</div>
				<StepTitle className="text-[18px]">{__('Proto Blocks is ready', 'proto-blocks')}</StepTitle>
			</div>
			<h2 className="mt-6 mb-2 text-sm font-medium">{__('Setup Summary', 'proto-blocks')}</h2>
			<dl className="divide-y rounded-md border text-sm">
				{rows.map((row) => (
					<div key={row.label} className="flex items-baseline justify-between gap-4 px-4 py-2.5">
						<dt className="text-muted-foreground">{row.label}</dt>
						<dd className="font-medium">{row.value}</dd>
					</div>
				))}
			</dl>
			{data.style === 'vanilla' && data.tailwindEnabled ? (
				<p className="mt-3 text-[13px] text-muted-foreground">
					{__('Tailwind stays enabled; turn it off in Tailwind Settings if you no longer need it.', 'proto-blocks')}
				</p>
			) : null}
			<div className="mt-8 flex justify-end">
				<Button onClick={onComplete} disabled={completing}>
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
		<div aria-busy="true" className="mx-auto grid max-w-[640px] gap-3 pt-8">
			<Skeleton className="h-6 w-56" />
			<Skeleton className="h-4 w-full" />
			<Skeleton className="h-24 w-full" />
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
			body = <Welcome onNext={() => setStep(2)} />;
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
		<div className="mx-auto max-w-[640px] pt-8">
			<div className="mb-6 flex justify-center">
				<LogoMark />
			</div>
			<Card className="gap-0 rounded-lg p-8 shadow-xs">
				<StepIndicator current={step} />
				{/* key remounts the step so its title takes focus */}
				<div key={step}>{body}</div>
			</Card>
		</div>
	);
}
