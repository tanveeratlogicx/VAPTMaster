// React Component to Render Generated Interfaces
// Version 2.0 - Functional & Persistent
// Expects props: { feature, onUpdate }

(function () {
  const { createElement: el, useState, useEffect } = wp.element;
  const { Button, TextControl, ToggleControl, SelectControl, TextareaControl, Card, CardBody } = wp.components;
  const { __ } = wp.i18n;

  const GeneratedInterface = ({ feature, onUpdate }) => {
    const schema = feature.generated_schema;
    const initialData = feature.implementation_data || {};

    // Internal state for snappy typing/toggling
    const [localData, setLocalData] = useState(initialData);

    if (!schema || !schema.controls || !Array.isArray(schema.controls)) {
      return el('div', { style: { padding: '20px', textAlign: 'center', color: '#999', fontStyle: 'italic' } },
        __('No functional controls defined for this implementation.', 'vapt-builder')
      );
    }

    const handleChange = (key, value) => {
      const updated = { ...localData, [key]: value };
      setLocalData(updated);
      if (onUpdate) {
        onUpdate(updated);
      }
    };

    const renderControl = (control) => {
      const { type, label, key, help, options, rows } = control;
      const value = localData[key] !== undefined ? localData[key] : (control.default || '');

      switch (type) {
        case 'toggle':
          return el(ToggleControl, {
            key, label, help,
            checked: !!value,
            onChange: (val) => handleChange(key, val)
          });

        case 'input':
          return el(TextControl, {
            key, label, help,
            value: value,
            onChange: (val) => handleChange(key, val)
          });

        case 'select':
          return el(SelectControl, {
            key, label, help,
            value: value,
            options: options || [],
            onChange: (val) => handleChange(key, val)
          });

        case 'textarea':
        case 'code':
          return el(TextareaControl, {
            key, label, help,
            value: value,
            rows: rows || 6,
            onChange: (val) => handleChange(key, val),
            style: type === 'code' ? { fontFamily: 'monospace', fontSize: '12px', background: '#f0f0f1' } : {}
          });

        default:
          return el('div', { key, style: { marginBottom: '10px', color: '#d63638' } },
            sprintf(__('Unknown control type: %s', 'vapt-builder'), type)
          );
      }
    };

    return el('div', { className: 'vaptm-generated-controls', style: { display: 'flex', flexDirection: 'column', gap: '15px' } },
      schema.controls.map(renderControl)
    );
  };

  window.VAPTM_GeneratedInterface = GeneratedInterface;
})();
