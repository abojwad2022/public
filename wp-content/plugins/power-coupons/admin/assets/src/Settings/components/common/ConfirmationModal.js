import { Dialog, Button, Title } from '@bsf/force-ui';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { __ } from '@wordpress/i18n';

const ConfirmationModal = ( {
	isOpen,
	onClose,
	onConfirm,
	title = __( 'Confirm Delete', 'power-coupons' ),
	message = __(
		'Are you sure you want to delete this item? This action cannot be undone.',
		'power-coupons'
	),
	confirmText = __( 'Delete', 'power-coupons' ),
	cancelText = __( 'Cancel', 'power-coupons' ),
	isLoading = false,
} ) => {
	const handleConfirm = () => {
		onConfirm();
	};

	return (
		<Dialog
			open={ isOpen }
			setOpen={ onClose }
			size="sm"
			className="max-w-md"
		>
			<Dialog.Backdrop />
			<Dialog.Panel>
				<div className="p-6">
					<div className="flex items-center gap-4 mb-4">
						<div className="flex-shrink-0">
							<ExclamationTriangleIcon className="h-8 w-8 text-red-500" />
						</div>
						<Title
							size="sm"
							tag="h3"
							title={ title }
							className="[&_h2]:text-gray-900"
						/>
					</div>

					<div className="mb-6">
						<p className="text-gray-600 text-sm font-normal">
							{ message }
						</p>
					</div>

					<div className="flex gap-3 justify-end">
						<Button
							variant="primary"
							onClick={ handleConfirm }
							disabled={ isLoading }
							destructive={ true }
						>
							{ confirmText }
						</Button>
						<Button
							variant="outline"
							onClick={ onClose }
							disabled={ isLoading }
						>
							{ cancelText }
						</Button>
					</div>
				</div>
			</Dialog.Panel>
		</Dialog>
	);
};

export default ConfirmationModal;
