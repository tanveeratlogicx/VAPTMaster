const { render, useState, useEffect, createElement: el } = wp.element;
const { TabPanel, Panel, PanelBody, PanelRow, Button, Dashicon, ToggleControl, SelectControl, Modal, TextControl, Spinner, Notice, Placeholder } = wp.components;
const apiFetch = wp.apiFetch;
const { __, sprintf } = wp.i18n;

const FeatureList = ({ features, updateFeature, loading, dataFiles, selectedFile, onSelectFile, onUpload }) => {
  const [filterStatus, setFilterStatus] = useState('all');
  const [sortBy, setSortBy] = useState('name');
  const [searchQuery, setSearchQuery] = useState('');

  // 1. Analytics
  const stats = {
    total: features.length,
    implemented: features.filter(f => f.status === 'implemented').length,
    in_progress: features.filter(f => f.status === 'in_progress').length,
    available: features.filter(f => f.status === 'available').length
  };

  // 2. Filter & Sort
  let processedFeatures = [...features];

  if (filterStatus !== 'all') {
    processedFeatures = processedFeatures.filter(f => f.status === filterStatus);
  }

  if (searchQuery) {
    const q = searchQuery.toLowerCase();
    processedFeatures = processedFeatures.filter(f =>
      (f.name || f.label).toLowerCase().includes(q) ||
      (f.description && f.description.toLowerCase().includes(q))
    );
  }

  processedFeatures.sort((a, b) => {
    if (sortBy === 'name') return (a.name || a.label).localeCompare(b.name || b.label);
    if (sortBy === 'status') {
      const priority = { 'implemented': 3, 'in_progress': 2, 'available': 1 };
      return priority[b.status] - priority[a.status];
    }
    return 0;
  });

  return el(PanelBody, { title: __('Exhaustive Feature List', 'vapt-master'), initialOpen: true }, [
    // Top Controls
    el('div', { key: 'controls', style: { marginBottom: '20px', background: '#f6f7f7', padding: '15px', borderRadius: '4px', border: '1px solid #dcdcde' } }, [
      // Source Selection
      el('div', { style: { display: 'flex', gap: '20px', alignItems: 'flex-end', marginBottom: '15px' } }, [
        el('div', { style: { flexGrow: 1 } }, el(SelectControl, {
          label: __('Feature Source (JSON)', 'vapt-master'),
          value: selectedFile,
          options: dataFiles,
          onChange: (val) => onSelectFile(val)
        })),
        el('div', null, [
          el('label', { className: 'components-base-control__label', style: { display: 'block', marginBottom: '4px' } }, __('Upload New Features', 'vapt-master')),
          el('input', { type: 'file', accept: '.json', onChange: (e) => e.target.files.length > 0 && onUpload(e.target.files[0]) })
        ])
      ]),
      // Analytics Bar
      el('div', { style: { display: 'flex', gap: '15px', padding: '10px', background: '#fff', border: '1px solid #c3c4c7', borderRadius: '4px', marginBottom: '15px', alignItems: 'center' } }, [
        el('span', { style: { fontWeight: 'bold' } }, __('Summary:', 'vapt-master')),
        el('span', { className: 'vaptm-badge-total' }, sprintf(__('Total: %d', 'vapt-master'), stats.total)),
        el('span', { className: 'vaptm-badge-implemented', style: { color: 'green', fontWeight: '500' } }, sprintf(__('Implemented: %d', 'vapt-master'), stats.implemented)),
        el('span', { className: 'vaptm-badge-progress', style: { color: '#d63638', fontWeight: '500' } }, sprintf(__('In Progress: %d', 'vapt-master'), stats.in_progress)),
      ]),
      // Filters & Sort
      el('div', { style: { display: 'flex', gap: '20px' } }, [
        el('div', { style: { flex: 1 } }, el(TextControl, {
          label: __('Search Features', 'vapt-master'),
          value: searchQuery,
          onChange: setSearchQuery,
          placeholder: __('Search by name or description...', 'vapt-master')
        })),
        el('div', { style: { flex: 1 } }, el(SelectControl, {
          label: __('Filter by Status', 'vapt-master'),
          value: filterStatus,
          options: [
            { label: __('All Features', 'vapt-master'), value: 'all' },
            { label: __('Implemented', 'vapt-master'), value: 'implemented' },
            { label: __('In Progress', 'vapt-master'), value: 'in_progress' },
            { label: __('Available', 'vapt-master'), value: 'available' },
          ],
          onChange: setFilterStatus
        })),
        el('div', { style: { flex: 1 } }, el(SelectControl, {
          label: __('Sort By', 'vapt-master'),
          value: sortBy,
          options: [
            { label: __('Name (A-Z)', 'vapt-master'), value: 'name' },
            { label: __('Status (Priority)', 'vapt-master'), value: 'status' },
          ],
          onChange: setSortBy
        }))
      ])
    ]),

    loading ? el(Spinner, { key: 'loader' }) : el('table', { key: 'table', className: 'wp-list-table widefat fixed striped' }, [
      el('thead', null, el('tr', null, [
        el('th', null, __('Feature Name', 'vapt-master')),
        el('th', null, __('Category', 'vapt-master')),
        el('th', null, __('Description', 'vapt-master')),
        el('th', null, __('Status', 'vapt-master')),
        el('th', null, __('Implemented At', 'vapt-master')),
        el('th', null, __('Include Test Method', 'vapt-master')),
        el('th', null, __('Include Verification', 'vapt-master')),
        el('th', null, __('Actions', 'vapt-master')),
      ])),
      el('tbody', null, processedFeatures.map((f) => el('tr', { key: f.key }, [
        el('td', null, [
          el('strong', null, f.name || f.label)
        ]),
        el('td', null, f.category),
        el('td', null, f.description),
        el('td', null, el(SelectControl, {
          value: f.status,
          options: [
            { label: __('Available', 'vapt-master'), value: 'available' },
            { label: __('In Progress', 'vapt-master'), value: 'in_progress' },
            { label: __('Implemented', 'vapt-master'), value: 'implemented' },
          ],
          onChange: (val) => updateFeature(f.key, { status: val })
        })),
        el('td', null, f.implemented_at ? new Date(f.implemented_at).toLocaleString() : '-'),
        el('td', null, el(ToggleControl, {
          checked: f.include_test_method,
          onChange: (val) => updateFeature(f.key, { include_test_method: val })
        })),
        el('td', null, el(ToggleControl, {
          checked: f.include_verification,
          onChange: (val) => updateFeature(f.key, { include_verification: val })
        })),
        el('td', null, el(Button, {
          isPrimary: true,
          onClick: () => updateFeature(f.key, { status: 'implemented' }),
          disabled: f.status === 'implemented'
        }, __('Mark Implemented', 'vapt-master')))
      ])))
    ])
  ]);
};

const VAPTMAdmin = () => {
  const [features, setFeatures] = useState([]);
  const [domains, setDomains] = useState([]);
  const [dataFiles, setDataFiles] = useState([]);
  const [selectedFile, setSelectedFile] = useState('features-with-test-methods.json');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isDomainModalOpen, setDomainModalOpen] = useState(false);
  const [selectedDomain, setSelectedDomain] = useState(null);
  const [saveStatus, setSaveStatus] = useState(null); // { message: '', type: 'info'|'success'|'error' }

  // Status Auto-clear helper
  useEffect(() => {
    if (saveStatus && saveStatus.type === 'success') {
      const timer = setTimeout(() => setSaveStatus(null), 2000);
      return () => clearTimeout(timer);
    }
  }, [saveStatus]);

  const fetchData = (file = selectedFile) => {
    console.log('VAPT Master: Fetching data for file:', file);
    setLoading(true);

    // Use individual catches to prevent one failure from blocking all
    const fetchFeatures = apiFetch({ path: `vaptm/v1/features?file=${file}` })
      .catch(err => { console.error('VAPT Master: Features fetch error:', err); return []; });
    const fetchDomains = apiFetch({ path: 'vaptm/v1/domains' })
      .catch(err => { console.error('VAPT Master: Domains fetch error:', err); return []; });
    const fetchDataFiles = apiFetch({ path: 'vaptm/v1/data-files' })
      .catch(err => { console.error('VAPT Master: Data files fetch error:', err); return []; });

    Promise.all([fetchFeatures, fetchDomains, fetchDataFiles])
      .then(([featureData, domainData, files]) => {
        console.log('VAPT Master: Partial/Full data fetch complete', {
          featuresFound: featureData.length,
          domainsFound: domainData.length,
          filesFound: files.length
        });

        if (featureData.length === 0 && file) {
          console.warn('VAPT Master: No features found for file:', file);
        }

        setFeatures(featureData);
        setDomains(domainData);
        setDataFiles(files);
        setLoading(false);
      })
      .catch((err) => {
        console.error('VAPT Master: Critical fetch error:', err);
        setError(err.message || __('Critical error loading dashboard data', 'vapt-master'));
        setLoading(false);
      });
  };

  useEffect(() => {
    fetchData();
  }, []);

  const updateFeature = (key, data) => {
    // Optimistic Update
    setFeatures(prev => prev.map(f => f.key === key ? { ...f, ...data } : f));
    setSaveStatus({ message: __('Saving...', 'vapt-master'), type: 'info' });

    apiFetch({
      path: 'vaptm/v1/features/update',
      method: 'POST',
      data: { key, ...data }
    }).then(() => {
      setSaveStatus({ message: __('Saved', 'vapt-master'), type: 'success' });
    }).catch(err => {
      console.error('Update failed:', err);
      setSaveStatus({ message: __('Error saving!', 'vapt-master'), type: 'error' });
    });
  };

  const addDomain = (domain, isWildcard = false) => {
    apiFetch({
      path: 'vaptm/v1/domains/update',
      method: 'POST',
      data: { domain, is_wildcard: isWildcard }
    }).then(() => fetchData());
  };

  const updateDomainFeatures = (domainId, updatedFeatures) => {
    // Optimistic Update
    setDomains(prev => prev.map(d => d.id === domainId ? { ...d, features: updatedFeatures } : d));
    setSaveStatus({ message: __('Saving...', 'vapt-master'), type: 'info' });

    apiFetch({
      path: 'vaptm/v1/domains/features',
      method: 'POST',
      data: { domain_id: domainId, features: updatedFeatures }
    }).then(() => {
      setSaveStatus({ message: __('Saved', 'vapt-master'), type: 'success' });
    }).catch(err => {
      console.error('Domain features update failed:', err);
      setSaveStatus({ message: __('Error saving!', 'vapt-master'), type: 'error' });
    });
  };

  const uploadJSON = (file) => {
    const formData = new FormData();
    formData.append('file', file);

    setLoading(true);
    apiFetch({
      path: 'vaptm/v1/upload-json',
      method: 'POST',
      body: formData,
    }).then((res) => {
      console.log('VAPT Master: JSON uploaded', res);
      fetchData(res.filename);
      setSelectedFile(res.filename);
    }).catch(err => {
      console.error('VAPT Master: Upload error:', err);
      alert(__('Error uploading JSON', 'vapt-master'));
      setLoading(false);
    });
  };


  const DomainFeatures = () => {
    const [newDomain, setNewDomain] = useState('');

    return el(PanelBody, { title: __('Domain Specific Features', 'vapt-master'), initialOpen: true }, [
      el('div', { key: 'add-domain', style: { marginBottom: '20px', display: 'flex', gap: '10px', alignItems: 'flex-end' } }, [
        el(TextControl, {
          label: __('Add New Domain', 'vapt-master'),
          value: newDomain,
          onChange: (val) => setNewDomain(val),
          placeholder: 'example.com'
        }),
        el(Button, {
          isPrimary: true,
          onClick: () => { addDomain(newDomain); setNewDomain(''); }
        }, __('Add Domain', 'vapt-master'))
      ]),
      el('table', { key: 'table', className: 'wp-list-table widefat fixed striped' }, [
        el('thead', null, el('tr', null, [
          el('th', null, __('Domain', 'vapt-master')),
          el('th', null, __('Type', 'vapt-master')),
          el('th', null, __('Features Enabled', 'vapt-master')),
          el('th', null, __('Actions', 'vapt-master')),
        ])),
        el('tbody', null, domains.map((d) => el('tr', { key: d.id }, [
          el('td', null, el('strong', null, d.domain)),
          el('td', null, d.is_wildcard ? __('Wildcard', 'vapt-master') : __('Standard', 'vapt-master')),
          el('td', null, `${d.features.length} ${__('Features', 'vapt-master')}`),
          el('td', null, el(Button, {
            isSecondary: true,
            onClick: () => { setSelectedDomain(d); setDomainModalOpen(true); }
          }, __('Manage Features', 'vapt-master')))
        ])))
      ]),
      isDomainModalOpen && selectedDomain && el(Modal, {
        key: 'modal',
        title: sprintf(__('Features for %s', 'vapt-master'), selectedDomain.domain),
        onRequestClose: () => setDomainModalOpen(false)
      }, [
        el('p', null, __('Select features to enable for this domain. Only "Implemented" features are available.', 'vapt-master')),
        el('div', { className: 'vaptm-feature-grid' }, features.filter(f => f.status === 'implemented').map(f => el(ToggleControl, {
          key: f.key,
          label: f.label,
          help: f.description,
          checked: selectedDomain.features.includes(f.key),
          onChange: (val) => {
            const newFeats = val
              ? [...selectedDomain.features, f.key]
              : selectedDomain.features.filter(k => k !== f.key);
            updateDomainFeatures(selectedDomain.id, newFeats);
            setSelectedDomain({ ...selectedDomain, features: newFeats });
          }
        }))),
        el('div', { style: { marginTop: '20px', textAlign: 'right' } }, el(Button, {
          isPrimary: true,
          onClick: () => setDomainModalOpen(false)
        }, __('Done', 'vapt-master')))
      ])
    ]);
  };

  const BuildGenerator = () => {
    const [buildDomain, setBuildDomain] = useState('');
    const [buildVersion, setBuildVersion] = useState('1.0.0');
    const [whiteLabel, setWhiteLabel] = useState({
      name: 'VAPT Master Client',
      description: 'Custom Security Build',
      author: 'Tan Malik'
    });
    const [generating, setGenerating] = useState(false);
    const [downloadUrl, setDownloadUrl] = useState(null);

    const runBuild = () => {
      setGenerating(true);
      setDownloadUrl(null);
      const selectedDomain = domains.find(d => d.domain === buildDomain);
      const buildFeatures = selectedDomain ? selectedDomain.features : features.filter(f => f.status === 'implemented').map(f => f.key);

      apiFetch({
        path: 'vaptm/v1/build/generate',
        method: 'POST',
        data: {
          domain: buildDomain,
          version: buildVersion,
          features: buildFeatures,
          white_label: whiteLabel
        }
      }).then((res) => {
        setDownloadUrl(res.download_url);
        setGenerating(false);
      }).catch(() => {
        setGenerating(false);
        alert(__('Build failed!', 'vapt-master'));
      });
    };

    return el(PanelBody, { title: __('Generate Customized Plugin Build', 'vapt-master'), initialOpen: true }, [
      el('div', { key: 'form', style: { maxWidth: '600px' } }, [
        el(SelectControl, {
          label: __('Select Target Domain', 'vapt-master'),
          value: buildDomain,
          options: [
            { label: __('--- Select Domain ---', 'vapt-master'), value: '' },
            { label: __('Wildcard (Include All Implemented Features)', 'vapt-master'), value: 'wildcard' },
            ...domains.map(d => ({ label: d.domain, value: d.domain }))
          ],
          onChange: (val) => setBuildDomain(val)
        }),
        el(TextControl, {
          label: __('Build Version', 'vapt-master'),
          value: buildVersion,
          onChange: (val) => setBuildVersion(val)
        }),
        el('h3', null, __('White Label Options', 'vapt-master')),
        el(TextControl, {
          label: __('Plugin Name', 'vapt-master'),
          value: whiteLabel.name,
          onChange: (val) => setWhiteLabel({ ...whiteLabel, name: val })
        }),
        el(TextControl, {
          label: __('Plugin Description', 'vapt-master'),
          value: whiteLabel.description,
          onChange: (val) => setWhiteLabel({ ...whiteLabel, description: val })
        }),
        el(TextControl, {
          label: __('Author Name', 'vapt-master'),
          value: whiteLabel.author,
          onChange: (val) => setWhiteLabel({ ...whiteLabel, author: val })
        }),
        el(Button, {
          isPrimary: true,
          isLarge: true,
          onClick: runBuild,
          disabled: !buildDomain || generating
        }, generating ? el(Spinner) : __('Generate Build ZIP', 'vapt-master')),
        downloadUrl && el('div', { key: 'download', style: { marginTop: '20px', padding: '15px', background: '#edeff0', borderLeft: '4px solid #00a0d2' } }, [
          el('p', null, el('strong', null, __('Build Ready!', 'vapt-master'))),
          el(Button, {
            isLink: true,
            href: downloadUrl,
            target: '_blank'
          }, __('Click here to download your custom plugin ZIP', 'vapt-master'))
        ])
      ])
    ]);
  };

  const LicenseTab = () => el(PanelBody, { title: __('License & Subscription Management', 'vapt-master'), initialOpen: true }, [
    el(Placeholder, {
      key: 'placeholder',
      icon: el(Dashicon, { icon: 'admin-network' }),
      label: __('License Keys', 'vapt-master'),
      instructions: __('Manage domain licenses and activation status here.', 'vapt-master')
    }, [
      el('table', { key: 'table', className: 'wp-list-table widefat fixed striped' }, [
        el('thead', null, el('tr', null, [
          el('th', null, __('Domain', 'vapt-master')),
          el('th', null, __('License Key', 'vapt-master')),
          el('th', null, __('Status', 'vapt-master')),
        ])),
        el('tbody', null, domains.map(d => el('tr', { key: d.id }, [
          el('td', null, d.domain),
          el('td', null, el('code', null, d.license_id || __('No License assigned', 'vapt-master'))),
          el('td', null, d.license_id ?
            el('span', { style: { color: 'green' } }, __('Active', 'vapt-master')) :
            el('span', { style: { color: 'red' } }, __('Inactive', 'vapt-master')))
        ])))
      ])
    ])
  ]);

  const tabs = [
    {
      name: 'features',
      title: __('Feature List', 'vapt-master'),
      className: 'vaptm-tab-features',
    },
    {
      name: 'license',
      title: __('License Management', 'vapt-master'),
      className: 'vaptm-tab-license',
    },
    {
      name: 'domains',
      title: __('Domain Features', 'vapt-master'),
      className: 'vaptm-tab-domains',
    },
    {
      name: 'build',
      title: __('Build Generator', 'vapt-master'),
      className: 'vaptm-tab-build',
    },
  ];

  if (error) {
    return el('div', { className: 'vaptm-admin-wrap' }, [
      el('h1', null, __('VAPT Master Dashboard', 'vapt-master')),
      el(Notice, { status: 'error', isDismissible: false }, error),
      el(Button, { isSecondary: true, onClick: () => fetchData() }, __('Retry', 'vapt-master'))
    ]);
  }

  return el('div', { className: 'vaptm-admin-wrap' }, [
    el('h1', null, __('VAPT Master Dashboard', 'vapt-master')),
    saveStatus && el('div', {
      style: {
        position: 'fixed',
        bottom: '20px',
        right: '20px',
        background: saveStatus.type === 'error' ? '#d63638' : '#2271b1',
        color: '#fff',
        padding: '10px 20px',
        borderRadius: '4px',
        boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
        zIndex: 100,
        fontWeight: '600',
        transition: 'opacity 0.3s ease-in-out'
      }
    }, saveStatus.message),
    el(TabPanel, {
      className: 'vaptm-main-tabs',
      activeClass: 'is-active',
      tabs: tabs
    }, (tab) => {
      switch (tab.name) {
        case 'features': return el(FeatureList, {
          features,
          updateFeature,
          loading,
          dataFiles,
          selectedFile,
          onSelectFile: (val) => { setSelectedFile(val); fetchData(val); },
          onUpload: uploadJSON
        });
        case 'license': return el(LicenseTab);
        case 'domains': return el(DomainFeatures);
        case 'build': return el(BuildGenerator);
        default: return null;
      }
    })
  ]);
};

const init = () => {
  const container = document.getElementById('vaptm-admin-root');
  console.log('VAPT Master: Init triggered. Container found:', !!container);

  if (container) {
    if (typeof wp === 'undefined' || !wp.element) {
      console.error('VAPT Master: wp.element is missing!');
      container.innerHTML = '<div class="notice notice-error"><p>Error: WordPress React (wp-element) is missing.</p></div>';
      return;
    }

    // WordPress 6.2+ way
    if (wp.element.createRoot) {
      console.log('VAPT Master: Using createRoot for rendering');
      wp.element.createRoot(container).render(el(VAPTMAdmin));
    } else if (typeof render === 'function') {
      console.log('VAPT Master: Using legacy render');
      render(el(VAPTMAdmin), container);
    } else {
      console.error('VAPT Master: wp.element.render is not a function');
      container.innerHTML = '<div class="notice notice-error"><p>Error: WordPress React (wp-element) not loaded correctly.</p></div>';
    }
  }
};

if (document.readyState === 'complete' || document.readyState === 'interactive') {
  console.log('VAPT Master: Document already ready, running init');
  init();
} else {
  console.log('VAPT Master: Waiting for DOMContentLoaded');
  document.addEventListener('DOMContentLoaded', init);
}
