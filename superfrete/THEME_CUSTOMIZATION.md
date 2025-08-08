# SuperFrete Theme Customization Guide

The SuperFrete WordPress plugin now supports full theme compatibility through CSS custom properties (CSS variables). This allows your theme to override the plugin's styling to match your design.

## CSS Variables Reference

The plugin uses the following CSS variables that can be overridden in your theme:

### Brand Colors
```css
--superfrete-primary-color: #0fae79;      /* Main brand color */
--superfrete-primary-hover: #0d9969;      /* Hover state for primary */
--superfrete-secondary-color: #EA961F;    /* Secondary brand color */
--superfrete-secondary-hover: #d88519;    /* Hover state for secondary */
```

### Theme-Inherited Colors
```css
--superfrete-text-color: inherit;         /* Inherits from theme text color */
--superfrete-heading-color: inherit;      /* Inherits from theme heading color */
--superfrete-bg-color: #f8f9fa;          /* Background color for containers */
--superfrete-bg-white: #ffffff;          /* White background (adapts in dark mode) */
--superfrete-border-color: #ddd;         /* Main border color */
--superfrete-border-light: #eaeaea;      /* Light border color */
```

### Status Colors
```css
--superfrete-success-color: #4CAF50;     /* Success states */
--superfrete-error-color: #e74c3c;       /* Error messages */
--superfrete-info-color: #2196F3;        /* Information messages */
```

### Typography
```css
--superfrete-font-family: inherit;        /* Inherits theme font */
--superfrete-font-size-base: 1rem;       /* Base font size */
--superfrete-font-size-small: 0.875rem;  /* Small text */
--superfrete-font-size-large: 1.125rem;  /* Large text */
--superfrete-font-weight-normal: normal; /* Normal weight */
--superfrete-font-weight-bold: bold;     /* Bold weight */
--superfrete-line-height: 1.5;           /* Line height */
```

### Spacing
```css
--superfrete-spacing-xs: 0.25rem;        /* Extra small spacing */
--superfrete-spacing-sm: 0.5rem;         /* Small spacing */
--superfrete-spacing-md: 1rem;           /* Medium spacing */
--superfrete-spacing-lg: 1.5rem;         /* Large spacing */
--superfrete-spacing-xl: 2rem;           /* Extra large spacing */
```

### Border Radius
```css
--superfrete-radius-sm: 4px;             /* Small radius */
--superfrete-radius-md: 6px;             /* Medium radius */
--superfrete-radius-lg: 8px;             /* Large radius */
```

### Shadows
```css
--superfrete-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);   /* Small shadow */
--superfrete-shadow-md: 0 2px 5px rgba(0, 0, 0, 0.1);   /* Medium shadow */
--superfrete-shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.15);  /* Large shadow */
```

### Z-index
```css
--superfrete-z-overlay: 999;             /* Overlay z-index */
--superfrete-z-popup: 1000;              /* Popup z-index */
--superfrete-z-loading: 1001;            /* Loading spinner z-index */
```

## How to Customize in Your Theme

### Method 1: Override in Your Theme's CSS

Add to your theme's CSS file:

```css
:root {
    /* Override SuperFrete colors to match your theme */
    --superfrete-primary-color: #your-color;
    --superfrete-secondary-color: #your-secondary;
    
    /* Match your theme's typography */
    --superfrete-font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    
    /* Adjust spacing to match your theme */
    --superfrete-spacing-md: 1.2rem;
    
    /* Custom border radius */
    --superfrete-radius-lg: 12px;
}
```

### Method 2: Target Specific Selectors

You can also override specific elements:

```css
/* Make the calculator blend with your theme */
#super-frete-shipping-calculator {
    --superfrete-bg-color: var(--your-theme-bg-color);
    --superfrete-text-color: var(--your-theme-text-color);
}

/* Custom button styling */
.superfrete-update-address-button,
.superfrete-recalculate-button {
    --superfrete-primary-color: var(--your-theme-button-color);
    font-family: var(--your-theme-button-font);
}
```

### Method 3: Dark Mode Support

The plugin includes basic dark mode support. Enhance it for your theme:

```css
@media (prefers-color-scheme: dark) {
    :root {
        --superfrete-bg-color: #1a1a1a;
        --superfrete-bg-white: #2a2a2a;
        --superfrete-border-color: #444;
        --superfrete-text-color: #e0e0e0;
    }
}

/* Or use your theme's dark mode class */
body.dark-mode {
    --superfrete-bg-color: var(--your-dark-bg);
    --superfrete-text-color: var(--your-dark-text);
}
```

## Complete Theme Integration Example

Here's a complete example for a custom theme:

```css
/* In your theme's style.css */
:root {
    /* Use your theme's color system */
    --superfrete-primary-color: var(--theme-primary, #0fae79);
    --superfrete-primary-hover: var(--theme-primary-dark, #0d9969);
    --superfrete-secondary-color: var(--theme-accent, #EA961F);
    
    /* Inherit your theme's typography */
    --superfrete-font-family: var(--theme-font-family, inherit);
    --superfrete-font-size-base: var(--theme-font-size, 1rem);
    
    /* Match your theme's spacing scale */
    --superfrete-spacing-sm: var(--theme-space-2, 0.5rem);
    --superfrete-spacing-md: var(--theme-space-4, 1rem);
    --superfrete-spacing-lg: var(--theme-space-6, 1.5rem);
    
    /* Use your theme's design tokens */
    --superfrete-radius-sm: var(--theme-radius-sm, 4px);
    --superfrete-shadow-md: var(--theme-shadow-md, 0 2px 5px rgba(0,0,0,0.1));
}
```

## PHP Filters for Advanced Customization

The plugin provides filters for advanced customization:

```php
// Add custom CSS variables
add_filter('superfrete_css_variables', function($variables) {
    $variables['--superfrete-primary-color'] = get_theme_mod('primary_color', '#0fae79');
    $variables['--superfrete-font-family'] = get_theme_mod('body_font', 'inherit');
    return $variables;
});

// Add custom classes to calculator container
add_filter('superfrete_calculator_classes', function($classes) {
    $classes[] = 'my-theme-calculator';
    return $classes;
});

// Modify calculator HTML attributes
add_filter('superfrete_calculator_attributes', function($attributes) {
    $attributes['data-theme'] = 'my-custom-theme';
    return $attributes;
});
```

## WordPress Customizer Integration

Add SuperFrete options to your theme's customizer:

```php
function my_theme_superfrete_customizer($wp_customize) {
    // SuperFrete Section
    $wp_customize->add_section('superfrete_styling', array(
        'title' => __('SuperFrete Styling', 'my-theme'),
        'priority' => 130,
    ));
    
    // Primary Color
    $wp_customize->add_setting('superfrete_primary_color', array(
        'default' => '#0fae79',
        'transport' => 'refresh',
    ));
    
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'superfrete_primary_color', array(
        'label' => __('SuperFrete Primary Color', 'my-theme'),
        'section' => 'superfrete_styling',
    )));
}
add_action('customize_register', 'my_theme_superfrete_customizer');

// Output custom CSS
function my_theme_superfrete_custom_css() {
    ?>
    <style>
        :root {
            --superfrete-primary-color: <?php echo esc_attr(get_theme_mod('superfrete_primary_color', '#0fae79')); ?>;
        }
    </style>
    <?php
}
add_action('wp_head', 'my_theme_superfrete_custom_css');
```

## Best Practices

1. **Use CSS Variables**: Always override CSS variables instead of directly targeting selectors when possible.

2. **Inherit from Theme**: Use `inherit` or your theme's CSS variables to maintain consistency.

3. **Respect User Preferences**: Support both light and dark modes if your theme does.

4. **Test Responsiveness**: Ensure your customizations work on all device sizes.

5. **Maintain Accessibility**: Keep sufficient color contrast and readable font sizes.

## Troubleshooting

### Styles Not Applying
- Ensure your CSS loads after the plugin's CSS
- Check CSS specificity - you may need to increase it
- Verify CSS variable names are correct

### Z-index Conflicts
- Adjust the z-index variables to work with your theme's layering system
- Use the provided variables instead of hardcoding z-index values

### Font Issues
- Make sure to include fallback fonts
- Test with different font stacks
- Consider loading web fonts if needed

## Support

For issues or questions about theme integration:
1. Check the plugin documentation
2. Review this customization guide
3. Contact SuperFrete support

Remember to test your customizations thoroughly across different browsers and devices to ensure a consistent experience for all users.