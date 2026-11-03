/**
 * group: its sub-fields inline, named `[group][sub]`, in a bordered group
 * labelled by the row label. Sub-field conditions read their siblings.
 */
import { FieldList } from '../FieldsForm';
import { useFieldA11y, type FieldInputProps } from '../registry';

export function GroupInput( { field, path }: FieldInputProps ) {
	const a11y = useFieldA11y();
	return (
		<div
			id={ a11y.id }
			role="group"
			aria-labelledby={ a11y.labelId }
			aria-describedby={ a11y.describedBy }
			className="rounded-lg border border-border bg-background p-4"
		>
			<FieldList fields={ field.subFields } scope={ path } />
		</div>
	);
}
