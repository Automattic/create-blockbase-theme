import { useEffect } from '@wordpress/element';
import Demo from '../demo-text-input/demo';
import { localizeFontStyle } from '../utils';

function FontVariant( { font, variant, isSelected, handleToggle } ) {
	const style = variant.includes( 'italic' ) ? 'italic' : 'normal';
	const weight =
		variant === 'regular' || variant === 'italic'
			? '400'
			: variant.replace( 'italic', '' );
	// Force https because sometimes Google Fonts API returns http instead of https
	const variantUrl = font.files[ variant ].replace( 'http://', 'https://' );
	const previewStyles = {
		fontFamily: font.family,
		fontStyle: style,
		fontWeight: weight,
	};

	useEffect( () => {
		// If font.family ends in number, add quotes
		const sanitizedFontFamily = font.family.match( /\d$/ )
			? `'${ font.family }'`
			: font.family;

		const newFont = new FontFace(
			sanitizedFontFamily,
			`url( ${ variantUrl } )`,
			{
				style,
				weight,
			}
		);

		const loadNewFont = async () => {
			try {
				const loadedFace = await newFont.load();
				document.fonts.add( loadedFace );
			} catch ( error ) {
				// TODO: show error in the UI
				// eslint-disable-next-line
				console.error( error );
			}
		};

		loadNewFont();
	}, [ font, style, variant, variantUrl, weight ] );

	const formattedFontFamily = font.family.toLowerCase().replace( ' ', '-' );
	const fontId = `${ formattedFontFamily }-${ variant }`;

	return (
		<tr>
			<td className="">
				<input
					type="checkbox"
					name="google-font-variant"
					id={ fontId }
					value={ variant }
					checked={ isSelected }
					onChange={ handleToggle }
				/>
			</td>
			<td className="">
				<label htmlFor={ fontId }>{ weight }</label>
			</td>
			<td className="">
				<label htmlFor={ fontId }>{ localizeFontStyle( style ) }</label>
			</td>
			<td className="demo-cell">
				<label htmlFor={ fontId }>
					<Demo style={ previewStyles } />
				</label>
			</td>
		</tr>
	);
}

export default FontVariant;
