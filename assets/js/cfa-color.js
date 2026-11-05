(function (wp) {
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var Dropdown = wp.components.Dropdown;
    var Button = wp.components.Button;
    var ColorPicker = wp.components.ColorPicker;

    var root = document.getElementById('cfa-color-picker');
    var input = document.getElementById('cf_analytics_primary_color');
    if (!root || !input) {
        return;
    }

    function Picker() {
        var state = useState(input.value);
        var color = state[0];
        var setColor = state[1];

        return el(Dropdown, {
            className: 'cfa-color-dropdown',
            renderToggle: function (o) {
                return el(Button, {
                    variant: 'secondary',
                    type: 'button',
                    onClick: o.onToggle,
                    'aria-expanded': o.isOpen
                },
                    el('span', {
                        style: {
                            display: 'inline-block',
                            width: '1rem',
                            height: '1rem',
                            borderRadius: '50%',
                            background: color,
                            border: '1px solid rgba(0,0,0,.15)',
                            marginRight: '.5rem',
                            verticalAlign: 'middle'
                        }
                    }),
                    color.toUpperCase()
                );
            },
            renderContent: function () {
                return el(ColorPicker, {
                    color: color,
                    enableAlpha: false,
                    onChange: function (value) {
                        var hex = typeof value === 'string' ? value : value.hex;
                        setColor(hex);
                        input.value = hex;
                    }
                });
            }
        });
    }

    var app = el(Picker);
    if (wp.element.createRoot) {
        wp.element.createRoot(root).render(app);
    } else {
        wp.element.render(app, root);
    }
})(window.wp);