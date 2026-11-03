/**
 * Layout-only fields (ACF semantics): `tab` splits the fields after it into
 * tabs, `accordion` wraps the fields after it in a collapsible section (an
 * accordion with `endpoint` closes the open one), `message` shows text.
 *
 * Every panel stays mounted (inactive tabs and closed sections are only
 * hidden), so every input still submits with the post form.
 */
import { Fragment, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { ChevronRightIcon } from 'lucide-react';
import { get, useFormState } from 'react-hook-form';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/admin/components/ui/tabs';
import type { FieldDef } from '../../model/types';
import { phpEmpty } from '../../model/normalize';
import { dottedPath, type FieldPath } from '../names';
import { useBox } from '../registry';

type FlowProps = {
	fields: FieldDef[];
	scope: FieldPath;
	renderField: ( field: FieldDef ) => ReactNode;
	/** Names of fields with a server error, to open the first tab that has one. */
	initialErrors?: Set< string >;
};

type Section = { head: FieldDef | null; items: FieldDef[] };

/** Splits at each `marker` field; items before the first marker get a null head. */
function split( fields: FieldDef[], isMarker: ( f: FieldDef ) => boolean ): Section[] {
	const sections: Section[] = [ { head: null, items: [] } ];
	for ( const f of fields ) {
		if ( isMarker( f ) ) {
			sections.push( { head: f, items: [] } );
		} else {
			sections[ sections.length - 1 ].items.push( f );
		}
	}
	return sections;
}

export function MessageField( { field }: { field: FieldDef } ) {
	const message = field.settings?.message;
	return (
		<div className="col-span-12 flex flex-col gap-1">
			{ field.label && <p className="text-label font-medium text-foreground">{ field.label }</p> }
			{ typeof message === 'string' && message !== '' && (
				<p className="text-caption whitespace-pre-line text-muted-foreground">{ message }</p>
			) }
		</div>
	);
}

function Accordions( { fields, renderField }: Pick< FlowProps, 'fields' | 'renderField' > ) {
	const sections = split( fields, ( f ) => f.type === 'accordion' );
	return (
		<>
			{ sections.map( ( section, i ) => {
				if ( ! section.head || ! phpEmpty( section.head.settings?.endpoint ) ) {
					// Fields before the first accordion, or after an endpoint, sit in the plain grid.
					return <Fragment key={ i }>{ section.items.map( renderField ) }</Fragment>;
				}
				return (
					<details
						key={ section.head.key || i }
						open={ ! phpEmpty( section.head.settings?.open ) }
						className="group/acc col-span-12 rounded-lg border border-border bg-background"
					>
						<summary className="flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2.5 text-label font-medium text-foreground select-none hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
							<ChevronRightIcon
								aria-hidden="true"
								className="size-4 text-muted-foreground transition-transform group-open/acc:rotate-90 motion-reduce:transition-none"
							/>
							{ section.head.label }
						</summary>
						<div className="grid grid-cols-12 gap-5 border-t border-divider p-4">{ section.items.map( renderField ) }</div>
					</details>
				);
			} ) }
		</>
	);
}

function TabErrorDot( { names, scope }: { names: string[]; scope: FieldPath } ) {
	const { control } = useBox();
	const paths = names.map( ( n ) => dottedPath( [ ...scope, n ] ) );
	const { errors } = useFormState( { control, name: paths } );
	if ( ! paths.some( ( p ) => get( errors, p ) ) ) {
		return null;
	}
	return (
		<>
			<span aria-hidden="true" className="size-1.5 rounded-full bg-destructive" />
			<span className="sr-only">{ __( '(has errors)', 'proto-blocks' ) }</span>
		</>
	);
}

/** How many value fields a tab holds, shown faint after its name (the name stays the tab's label). */
function TabCount( { fields }: { fields: FieldDef[] } ) {
	const n = fields.filter( ( f ) => f.name !== '' && ! [ 'tab', 'accordion', 'message' ].includes( f.type ) ).length;
	if ( n === 0 ) {
		return null;
	}
	return (
		<span aria-hidden="true" className="text-micro font-normal text-faint tabular-nums">
			{ n }
		</span>
	);
}

/** Lays out one scope's fields: tabs, then accordions inside each tab. */
export function FieldFlow( { fields, scope, renderField, initialErrors }: FlowProps ) {
	const [ before, ...tabs ] = split( fields, ( f ) => f.type === 'tab' );
	if ( tabs.length === 0 ) {
		return <Accordions fields={ fields } renderField={ renderField } />;
	}
	const value = ( i: number ) => tabs[ i ].head!.key || String( i );
	const withError = initialErrors ? tabs.findIndex( ( t ) => t.items.some( ( f ) => initialErrors.has( f.name ) ) ) : -1;
	return (
		<>
			<Accordions fields={ before.items } renderField={ renderField } />
			<Tabs defaultValue={ value( Math.max( withError, 0 ) ) } className="col-span-12 gap-5">
				<TabsList variant="line" className="h-auto w-full justify-start gap-0.5 rounded-none border-b border-border p-0">
					{ tabs.map( ( t, i ) => (
						<TabsTrigger
							key={ value( i ) }
							value={ value( i ) }
							className="h-auto flex-none rounded-none px-3.5 py-2 text-body group-data-[orientation=horizontal]/tabs:after:bottom-[-1px]"
						>
							{ t.head!.label }
							<TabCount fields={ t.items } />
							<TabErrorDot names={ t.items.map( ( f ) => f.name ).filter( Boolean ) } scope={ scope } />
						</TabsTrigger>
					) ) }
				</TabsList>
				{ tabs.map( ( t, i ) => (
					<TabsContent
						key={ value( i ) }
						value={ value( i ) }
						forceMount
						className="grid grid-cols-12 gap-5 data-[state=inactive]:hidden"
					>
						<Accordions fields={ t.items } renderField={ renderField } />
					</TabsContent>
				) ) }
			</Tabs>
		</>
	);
}
