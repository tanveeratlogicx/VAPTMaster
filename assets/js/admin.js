// Global check-in for diagnostics - ABSOLUTE TOP
window.vaptmScriptLoaded = true;
console.log('VAPT Builder: Admin JS bundle loaded and executing...');

(function () {
  if (typeof wp === 'undefined') {
    console.error('VAPT Builder: "wp" global is missing!');
    return;
  }

  const { render, useState, useEffect, createElement: el } = wp.element || {};
  const {
    TabPanel, Panel, PanelBody, PanelRow, Button, Dashicon,
    ToggleControl, SelectControl, Modal, TextControl, Spinner,
    Notice, Placeholder, Dropdown, CheckboxControl
  } = wp.components || {};
  const apiFetch = wp.apiFetch;
  const { __, sprintf } = wp.i18n || {};

  if (!wp.element || !wp.components || !wp.apiFetch || !wp.i18n) {
    console.error('VAPT Builder: One or more WordPress dependencies are missing!', {
      element: !!wp.element,
      components: !!wp.components,
      apiFetch: !!wp.apiFetch,
      i18n: !!wp.i18n
    });
    return;
  }

  const FeatureList = ({ features, updateFeature, loading, dataFiles, selectedFile, onSelectFile, onUpload, allFiles, hiddenFiles, onUpdateHiddenFiles, isManageModalOpen, setIsManageModalOpen, assignees, onAssign }) => {
    const [filterStatus, setFilterStatus] = useState(() => localStorage.getItem('vaptm_filter_status') || 'all');
    const [selectedCategories, setSelectedCategories] = useState(() => {
      const saved = localStorage.getItem('vaptm_selected_categories');
      return saved ? JSON.parse(saved) : [];
    });
    const [sortBy, setSortBy] = useState(() => localStorage.getItem('vaptm_sort_by') || 'name');
    const [searchQuery, setSearchQuery] = useState(() => localStorage.getItem('vaptm_search_query') || '');

    // Persist filters
    useEffect(() => {
      localStorage.setItem('vaptm_filter_status', filterStatus);
      localStorage.setItem('vaptm_selected_categories', JSON.stringify(selectedCategories));
      localStorage.setItem('vaptm_sort_by', sortBy);
      localStorage.setItem('vaptm_search_query', searchQuery);
    }, [filterStatus, selectedCategories, sortBy, searchQuery]);

    const [historyFeature, setHistoryFeature] = useState(null);
    const [transitioning, setTransitioning] = useState(null); // { key, nextStatus, note }

    const confirmTransition = () => {
      if (!transitioning) return;
      updateFeature(transitioning.key, {
        status: transitioning.nextStatus,
        transition_note: transitioning.note
      });
      setTransitioning(null);
    };

    // 1. Analytics (Moved below filtering for scope)

    // 2. Extract Categories
    const categories = [...new Set(features.map(f => f.category))].filter(Boolean).sort();

    // 3. Filter & Sort
    let processedFeatures = [...features];

    // Category Filter First
    if (selectedCategories.length > 0) {
      processedFeatures = processedFeatures.filter(f => selectedCategories.includes(f.category));
    }

    // Analytics (Scoped to Category selection)
    const stats = {
      total: processedFeatures.length,
      draft: processedFeatures.filter(f => f.status === 'draft' || f.status === 'available').length, // Map available to draft count
      develop: processedFeatures.filter(f => f.status === 'develop' || f.status === 'in_progress').length,
      test: processedFeatures.filter(f => f.status === 'test').length,
      release: processedFeatures.filter(f => f.status === 'release' || f.status === 'implemented').length
    };

    // Status Filter Second
    if (filterStatus !== 'all') {
      processedFeatures = processedFeatures.filter(f => {
        if (filterStatus === 'draft') return f.status === 'draft' || f.status === 'available';
        if (filterStatus === 'develop') return f.status === 'develop' || f.status === 'in_progress';
        if (filterStatus === 'release') return f.status === 'release' || f.status === 'implemented';
        return f.status === filterStatus;
      });
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
        const priority = { 'release': 4, 'implemented': 4, 'test': 3, 'develop': 2, 'in_progress': 2, 'draft': 1, 'available': 1 };
        return (priority[b.status] || 0) - (priority[a.status] || 0);
      }
      return 0;
    });

    // History Modal Component
    const HistoryModal = ({ feature, onClose }) => {
      const [history, setHistory] = useState([]);
      const [loading, setLoading] = useState(true);

      useEffect(() => {
        apiFetch({ path: `vaptm/v1/features/${feature.key}/history` })
          .then(res => {
            setHistory(res);
            setLoading(false);
          })
          .catch(() => setLoading(false));
      }, [feature.key]);

      return el(Modal, {
        title: sprintf(__('History: %s', 'vapt-builder'), feature.name || feature.label),
        onRequestClose: onClose,
        className: 'vaptm-history-modal'
      }, [
        loading ? el(Spinner) : el('div', { style: { minWidth: '500px' } }, [
          history.length === 0 ? el('p', null, __('No history recorded yet.', 'vapt-builder')) :
            el('table', { className: 'wp-list-table widefat fixed striped' }, [
              el('thead', null, el('tr', null, [
                el('th', { style: { width: '120px' } }, __('Date', 'vapt-builder')),
                el('th', { style: { width: '100px' } }, __('From', 'vapt-builder')),
                el('th', { style: { width: '100px' } }, __('To', 'vapt-builder')),
                el('th', { style: { width: '120px' } }, __('User', 'vapt-builder')),
                el('th', null, __('Note', 'vapt-builder')),
              ])),
              el('tbody', null, history.map((h, i) => el('tr', { key: i }, [
                el('td', null, new Date(h.created_at).toLocaleString()),
                el('td', null, el('span', { className: `vaptm-status-badge status-${h.old_status}` }, h.old_status)),
                el('td', null, el('span', { className: `vaptm-status-badge status-${h.new_status}` }, h.new_status)),
                el('td', null, h.user_name || __('System', 'vapt-builder')),
                el('td', null, h.note || '-')
              ])))
            ])
        ]),
        el('div', { style: { marginTop: '20px', textAlign: 'right' } }, [
          el(Button, { isPrimary: true, onClick: onClose }, __('Close', 'vapt-builder'))
        ])
      ]);
    };

    const LifecycleIndicator = ({ feature, onChange }) => {
      const activeStep = feature.status === 'available' ? 'draft' :
        (feature.status === 'in_progress' ? 'develop' :
          (feature.status === 'implemented' ? 'release' : feature.status));

      const steps = [
        { id: 'draft', label: __('Draft', 'vapt-builder') },
        { id: 'develop', label: __('Develop', 'vapt-builder') },
        { id: 'test', label: __('Test', 'vapt-builder') },
        { id: 'release', label: __('Release', 'vapt-builder') }
      ];

      return el('div', { className: 'vaptm-lifecycle-radios', style: { display: 'flex', gap: '10px', fontSize: '12px', alignItems: 'center' } }, [
        ...steps.map((step) => {
          const isChecked = step.id === activeStep;
          return el('label', {
            key: step.id,
            style: { display: 'flex', alignItems: 'center', gap: '4px', cursor: 'pointer', color: isChecked ? '#2271b1' : 'inherit', fontWeight: isChecked ? '600' : 'normal' }
          }, [
            el('input', {
              type: 'radio',
              name: `lifecycle_${feature.key}_${Math.random()}`,
              checked: isChecked,
              onChange: () => onChange(step.id),
              style: { margin: 0 }
            }),
            step.label
          ]);
        })
      ]);
    };

    return el(PanelBody, { title: __('Exhaustive Feature List', 'vapt-builder'), initialOpen: true }, [
      // Top Controls
      el('div', { key: 'controls', style: { marginBottom: '20px', background: '#f6f7f7', padding: '15px', borderRadius: '4px', border: '1px solid #dcdcde' } }, [
        // Source Selection
        el('div', { style: { display: 'flex', gap: '20px', alignItems: 'flex-end', marginBottom: '15px' } }, [
          el('div', { style: { flexGrow: 1, display: 'flex', gap: '10px', alignItems: 'flex-end' } }, [
            el('div', { style: { flexGrow: 1 } }, el(SelectControl, {
              label: __('Feature Source (JSON)', 'vapt-builder'),
              value: selectedFile,
              options: dataFiles,
              onChange: (val) => onSelectFile(val)
            })),
            el(Button, {
              isSecondary: true,
              icon: 'admin-settings',
              onClick: () => setIsManageModalOpen(true),
              label: __('Manage JSON Sources', 'vapt-builder'),
              style: { marginBottom: '8px' }
            })
          ]),
          el('div', null, [
            el('label', { className: 'components-base-control__label', style: { display: 'block', marginBottom: '4px' } }, __('Upload New Features', 'vapt-builder')),
            el('input', { type: 'file', accept: '.json', onChange: (e) => e.target.files.length > 0 && onUpload(e.target.files[0]) })
          ])
        ]),

        // Manage Sources Modal
        isManageModalOpen && el(Modal, {
          title: __('Manage JSON Sources', 'vapt-builder'),
          onRequestClose: () => setIsManageModalOpen(false)
        }, [
          el('p', null, __('Deselect files to hide them from the Feature Source dropdown. The active file cannot be hidden.', 'vapt-builder')),
          el('div', { style: { maxHeight: '400px', overflowY: 'auto' } }, [
            allFiles.map(file => el(CheckboxControl, {
              key: file.filename,
              label: file.display_name || file.filename.replace(/_/g, ' '),
              checked: !hiddenFiles.includes(file.filename),
              disabled: file.filename === selectedFile,
              onChange: (val) => {
                const newHidden = val
                  ? hiddenFiles.filter(h => h !== file.filename)
                  : [...hiddenFiles, file.filename];
                onUpdateHiddenFiles(newHidden);
              }
            }))
          ]),
          el('div', { style: { marginTop: '20px', textAlign: 'right' } }, [
            el(Button, { isPrimary: true, onClick: () => setIsManageModalOpen(false) }, __('Close', 'vapt-builder'))
          ])
        ]),
        // Analytics Bar
        el('div', { style: { display: 'flex', gap: '15px', padding: '10px', background: '#fff', border: '1px solid #c3c4c7', borderRadius: '4px', marginBottom: '15px', alignItems: 'center', flexWrap: 'wrap' } }, [
          el('span', { style: { fontWeight: 'bold' } }, __('Summary:', 'vapt-builder')),
          el('span', { className: 'vaptm-badge-total' }, sprintf(__('Total: %d', 'vapt-builder'), stats.total)),
          el('span', { className: 'vaptm-badge-draft', style: { color: '#666' } }, sprintf(__('Draft: %d', 'vapt-builder'), stats.draft)),
          el('span', { className: 'vaptm-badge-develop', style: { color: '#d63638' } }, sprintf(__('Develop: %d', 'vapt-builder'), stats.develop)),
          el('span', { className: 'vaptm-badge-test', style: { color: '#dba617' } }, sprintf(__('Test: %d', 'vapt-builder'), stats.test)),
          el('span', { className: 'vaptm-badge-release', style: { color: 'green', fontWeight: 'bold' } }, sprintf(__('Release: %d', 'vapt-builder'), stats.release)),
        ]),
        // Filters & Sort
        el('div', { style: { display: 'flex', gap: '20px', flexWrap: 'wrap', alignItems: 'flex-end' } }, [
          el('div', { style: { flex: '1 1 200px' } }, el(TextControl, {
            label: __('Search Features', 'vapt-builder'),
            value: searchQuery,
            onChange: setSearchQuery,
            placeholder: __('Search by name or description...', 'vapt-builder')
          })),

          // Category Dropdown with Checkboxes
          el('div', { style: { flex: '0 0 auto' } }, [
            el('label', { className: 'components-base-control__label', style: { display: 'block', marginBottom: '8px', textTransform: 'uppercase', fontSize: '11px', fontWeight: '500', color: '#1e1e1e' } }, __('Filter by Category', 'vapt-builder')),
            el(Dropdown, {
              renderToggle: ({ isOpen, onToggle }) => el(Button, {
                isSecondary: true,
                onClick: onToggle,
                'aria-expanded': isOpen,
                icon: 'filter',
                style: { height: '30px', minHeight: '30px' } // Match SelectControl height
              }, selectedCategories.length === 0 ? __('All Categories', 'vapt-builder') : sprintf(__('%d Selected', 'vapt-builder'), selectedCategories.length)),
              renderContent: () => el('div', { style: { padding: '15px', minWidth: '250px', maxHeight: '300px', overflowY: 'auto' } }, [
                // All Categories Option
                el(CheckboxControl, {
                  label: __('All Categories', 'vapt-builder'),
                  checked: selectedCategories.length === 0,
                  onChange: () => setSelectedCategories([])
                }),
                el('hr', { style: { margin: '10px 0' } }),
                // Individual Categories
                ...categories.map(cat => el(CheckboxControl, {
                  key: cat,
                  label: cat,
                  checked: selectedCategories.includes(cat),
                  onChange: (isChecked) => {
                    if (isChecked) {
                      setSelectedCategories([...selectedCategories, cat]);
                    } else {
                      setSelectedCategories(selectedCategories.filter(c => c !== cat));
                    }
                  }
                }))
              ])
            })
          ]),

          el('div', { style: { flex: '1 1 150px' } }, el(SelectControl, {
            label: __('Filter by Status', 'vapt-builder'),
            value: filterStatus,
            options: [
              { label: __('All Features', 'vapt-builder'), value: 'all' },
              { label: __('Draft', 'vapt-builder'), value: 'draft' },
              { label: __('Develop', 'vapt-builder'), value: 'develop' },
              { label: __('Test', 'vapt-builder'), value: 'test' },
              { label: __('Release', 'vapt-builder'), value: 'release' },
            ],
            onChange: setFilterStatus
          })),
          el('div', { style: { flex: '1 1 150px' } }, el(SelectControl, {
            label: __('Sort By', 'vapt-builder'),
            value: sortBy,
            options: [
              { label: __('Name (A-Z)', 'vapt-builder'), value: 'name' },
              { label: __('Status (Priority)', 'vapt-builder'), value: 'status' },
            ],
            onChange: setSortBy
          }))
        ])
      ]),

      loading ? el(Spinner, { key: 'loader' }) : el('table', { key: 'table', className: 'wp-list-table widefat fixed striped vaptm-feature-table' }, [
        el('thead', null, el('tr', null, [
          el('th', { style: { width: '280px', whiteSpace: 'nowrap' } }, __('Feature Name', 'vapt-builder')),
          el('th', { style: { width: '100px' } }, __('Category', 'vapt-builder')),
          el('th', null, __('Description', 'vapt-builder')),
          el('th', { style: { width: '380px' } }, __('Lifecycle Status', 'vapt-builder')),
          el('th', { style: { width: '150px' } }, __('Assigned To', 'vapt-builder')),
          el('th', { style: { width: '150px' } }, __('Updated', 'vapt-builder')),
          el('th', { style: { width: '180px' } }, __('Include', 'vapt-builder')),
        ])),
        el('tbody', null, processedFeatures.map((f) => el('tr', { key: f.key }, [
          el('td', { style: { width: '280px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }, [
            el('strong', null, f.label)
          ]),
          el('td', null, f.category),
          el('td', null, f.description),
          el('td', { style: { display: 'flex', gap: '10px', alignItems: 'center' } }, [
            el(LifecycleIndicator, {
              feature: f,
              onChange: (newStatus) => setTransitioning({ key: f.key, nextStatus: newStatus, note: '' })
            }),
            el(Button, {
              icon: 'backup',
              isSmall: true,
              isTertiary: true,
              onClick: () => setHistoryFeature(f),
              label: __('View History', 'vapt-builder'),
              style: { marginLeft: 'auto' }
            })
          ]),
          el('td', null, el(SelectControl, {
            value: f.assigned_to,
            options: [
              { label: __('Unassigned', 'vapt-builder'), value: '' },
              ...assignees.map(u => ({ label: u.name, value: u.id }))
            ],
            onChange: (val) => onAssign(f.key, val),
            style: { marginBottom: 0 }
          })),
          el('td', null, f.implemented_at ? new Date(f.implemented_at).toLocaleString() : '-'),
          el('td', { className: 'vaptm-support-cell' }, el('div', { style: { display: 'flex', flexDirection: 'column', gap: '0px' } }, [
            el(ToggleControl, {
              label: __('Test Method', 'vapt-builder'),
              checked: f.include_test_method,
              disabled: f.status === 'draft' || f.status === 'available',
              onChange: (val) => updateFeature(f.key, { include_test_method: val }),
              style: { marginBottom: '0px' }
            }),
            el(ToggleControl, {
              label: __('Verification Steps', 'vapt-builder'),
              checked: f.include_verification,
              disabled: f.status === 'draft' || f.status === 'available',
              onChange: (val) => updateFeature(f.key, { include_verification: val }),
              style: { marginBottom: '0px' }
            })
          ])),
        ])), // End Map(Tr)
        ), // End Tbody
      ]), // End Table (Children Array, El)

      // History Modal (Sibling in PanelBody Array)
      historyFeature && el(HistoryModal, {
        feature: historyFeature,
        onClose: () => setHistoryFeature(null)
      }),

      // Transition Note Modal (Sibling in PanelBody Array)
      transitioning && el(Modal, {
        title: __('Status Change Note', 'vapt-builder'),
        onRequestClose: () => setTransitioning(null),
        className: 'vaptm-transition-modal'
      }, [
        el('p', null, sprintf(__('Moving "%s" to %s. Add an optional note for the history log:', 'vapt-builder'), transitioning.key, transitioning.nextStatus)),
        el(TextControl, {
          label: __('Note', 'vapt-builder'),
          value: transitioning.note,
          onChange: (val) => setTransitioning({ ...transitioning, note: val }),
          autoFocus: true
        }),
        el('div', { style: { marginTop: '20px', textAlign: 'right' } }, [
          el(Button, { isSecondary: true, onClick: () => setTransitioning(null), style: { marginRight: '10px' } }, __('Cancel', 'vapt-builder')),
          el(Button, { isPrimary: true, onClick: confirmTransition }, __('Confirm Status Change', 'vapt-builder'))
        ])
      ])
    ]);
  };

  const VAPTMAdmin = () => {
    const [features, setFeatures] = useState([]);
    const [domains, setDomains] = useState([]);
    const [dataFiles, setDataFiles] = useState([]);
    const [assignees, setAssignees] = useState([]);
    const [selectedFile, setSelectedFile] = useState(() => localStorage.getItem('vaptm_selected_file') || 'features-with-test-methods.json');
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
      const fetchAssignees = apiFetch({ path: 'vaptm/v1/assignees' })
        .catch(err => { console.error('VAPT Master: Assignees fetch error:', err); return []; });

      return Promise.all([fetchFeatures, fetchDomains, fetchDataFiles, fetchAssignees])
        .then(([featureData, domainData, files, assigneeData]) => {
          const cleanedFiles = (files || []).map(f => ({ ...f, label: (f.label || f.filename).replace(/_/g, ' ') }));
          setFeatures(featureData || []);
          setDomains(domainData || []);
          setDataFiles(cleanedFiles);
          setAssignees(assigneeData || []);
          setLoading(false);
        })
        .catch((err) => {
          console.error('VAPT Master: Dashboard data fetch error:', err);
          setError(sprintf(__('Critical error loading dashboard data: %s', 'vapt-builder'), err.message || 'Unknown error'));
          setLoading(false);
        });
    };

    useEffect(() => {
      fetchData();
    }, []);

    useEffect(() => {
      localStorage.setItem('vaptm_selected_file', selectedFile);
    }, [selectedFile]);

    const updateFeature = (key, data) => {
      // Optimistic Update
      setFeatures(prev => prev.map(f => f.key === key ? { ...f, ...data } : f));
      setSaveStatus({ message: __('Saving...', 'vapt-builder'), type: 'info' });

      apiFetch({
        path: 'vaptm/v1/features/update',
        method: 'POST',
        data: { key, ...data }
      }).then(() => {
        setSaveStatus({ message: __('Saved', 'vapt-builder'), type: 'success' });
      }).catch(err => {
        console.error('Update failed:', err);
        setSaveStatus({ message: __('Error saving!', 'vapt-builder'), type: 'error' });
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
      setSaveStatus({ message: __('Saving...', 'vapt-builder'), type: 'info' });

      apiFetch({
        path: 'vaptm/v1/domains/features',
        method: 'POST',
        data: { domain_id: domainId, features: updatedFeatures }
      }).then(() => {
        setSaveStatus({ message: __('Saved', 'vapt-builder'), type: 'success' });
      }).catch(err => {
        console.error('Domain features update failed:', err);
        setSaveStatus({ message: __('Error saving!', 'vapt-builder'), type: 'error' });
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
        // Fetch fresh data (including file list) THEN update selection
        fetchData().then(() => { // Call fetchData without arguments to refresh all data, including dataFiles
          setSelectedFile(res.filename);
        });
      }).catch(err => {
        console.error('VAPT Master: Upload error:', err);
        alert(__('Error uploading JSON', 'vapt-builder'));
        setLoading(false);
      });
    };

    const [allFiles, setAllFiles] = useState([]);
    const [hiddenFiles, setHiddenFiles] = useState([]);
    const [isManageModalOpen, setIsManageModalOpen] = useState(false);

    const fetchAllFiles = () => {
      apiFetch({ path: 'vaptm/v1/data-files/all' }).then(res => {
        // Clean display filenames (underscores to spaces)
        const cleaned = res.map(f => ({ ...f, display_name: f.filename.replace(/_/g, ' ') }));
        setAllFiles(cleaned);
        setHiddenFiles(res.filter(f => f.isHidden).map(f => f.filename));
      });
    };

    useEffect(() => {
      if (isManageModalOpen) {
        fetchAllFiles();
      }
    }, [isManageModalOpen]);

    const updateHiddenFiles = (newHidden) => {
      setHiddenFiles(newHidden);
      apiFetch({
        path: 'vaptm/v1/update-hidden-files',
        method: 'POST',
        data: { hidden_files: newHidden }
      }).then(() => {
        fetchData(); // Refresh dropdown list
      });
    };


    const DomainFeatures = () => {
      const [newDomain, setNewDomain] = useState('');

      return el(PanelBody, { title: __('Domain Specific Features', 'vapt-builder'), initialOpen: true }, [
        el('div', { key: 'add-domain', style: { marginBottom: '20px', display: 'flex', gap: '10px', alignItems: 'flex-end' } }, [
          el(TextControl, {
            label: __('Add New Domain', 'vapt-builder'),
            value: newDomain,
            onChange: (val) => setNewDomain(val),
            placeholder: 'example.com'
          }),
          el(Button, {
            isPrimary: true,
            onClick: () => { addDomain(newDomain); setNewDomain(''); }
          }, __('Add Domain', 'vapt-builder'))
        ]),
        el('table', { key: 'table', className: 'wp-list-table widefat fixed striped' }, [
          el('thead', null, el('tr', null, [
            el('th', null, __('Domain', 'vapt-builder')),
            el('th', null, __('Type', 'vapt-builder')),
            el('th', null, __('Features Enabled', 'vapt-builder')),
            el('th', null, __('Actions', 'vapt-builder')),
          ])),
          el('tbody', null, domains.map((d) => el('tr', { key: d.id }, [
            el('td', null, el('strong', null, d.domain)),
            el('td', null, d.is_wildcard ? __('Wildcard', 'vapt-builder') : __('Standard', 'vapt-builder')),
            el('td', null, `${d.features.length} ${__('Features', 'vapt-builder')}`),
            el('td', null, el(Button, {
              isSecondary: true,
              onClick: () => { setSelectedDomain(d); setDomainModalOpen(true); }
            }, __('Manage Features', 'vapt-builder')))
          ])))
        ]),
        isDomainModalOpen && selectedDomain && el(Modal, {
          key: 'modal',
          title: sprintf(__('Features for %s', 'vapt-builder'), selectedDomain.domain),
          onRequestClose: () => setDomainModalOpen(false)
        }, [
          el('p', null, __('Select features to enable for this domain. Only "Implemented" features are available.', 'vapt-builder')),
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
          }, __('Done', 'vapt-builder')))
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
          alert(__('Build failed!', 'vapt-builder'));
        });
      };

      return el(PanelBody, { title: __('Generate Customized Plugin Build', 'vapt-builder'), initialOpen: true }, [
        el('div', { key: 'form', style: { maxWidth: '600px' } }, [
          el(SelectControl, {
            label: __('Select Target Domain', 'vapt-builder'),
            value: buildDomain,
            options: [
              { label: __('--- Select Domain ---', 'vapt-builder'), value: '' },
              { label: __('Wildcard (Include All Implemented Features)', 'vapt-builder'), value: 'wildcard' },
              ...domains.map(d => ({ label: d.domain, value: d.domain }))
            ],
            onChange: (val) => setBuildDomain(val)
          }),
          el(TextControl, {
            label: __('Build Version', 'vapt-builder'),
            value: buildVersion,
            onChange: (val) => setBuildVersion(val)
          }),
          el('h3', null, __('White Label Options', 'vapt-builder')),
          el(TextControl, {
            label: __('Plugin Name', 'vapt-builder'),
            value: whiteLabel.name,
            onChange: (val) => setWhiteLabel({ ...whiteLabel, name: val })
          }),
          el(TextControl, {
            label: __('Plugin Description', 'vapt-builder'),
            value: whiteLabel.description,
            onChange: (val) => setWhiteLabel({ ...whiteLabel, description: val })
          }),
          el(TextControl, {
            label: __('Author Name', 'vapt-builder'),
            value: whiteLabel.author,
            onChange: (val) => setWhiteLabel({ ...whiteLabel, author: val })
          }),
          el(Button, {
            isPrimary: true,
            isLarge: true,
            onClick: runBuild,
            disabled: !buildDomain || generating
          }, generating ? el(Spinner) : __('Generate Build ZIP', 'vapt-builder')),
          downloadUrl && el('div', { key: 'download', style: { marginTop: '20px', padding: '15px', background: '#edeff0', borderLeft: '4px solid #00a0d2' } }, [
            el('p', null, el('strong', null, __('Build Ready!', 'vapt-builder'))),
            el(Button, {
              isLink: true,
              href: downloadUrl,
              target: '_blank'
            }, __('Click here to download your custom plugin ZIP', 'vapt-builder'))
          ])
        ])
      ]);
    };

    const onAssign = (key, userId) => {
      apiFetch({
        path: 'vaptm/v1/features/assign',
        method: 'POST',
        data: { key, user_id: userId }
      }).then(() => {
        setSaveStatus({ message: __('Feature assigned successfully.', 'vapt-builder'), type: 'success' });
        fetchData();
      });
    };

    const LicenseTab = () => el(PanelBody, { title: __('License & Subscription Management', 'vapt-builder'), initialOpen: true }, [
      el(Placeholder, {
        key: 'placeholder',
        icon: el(Dashicon, { icon: 'admin-network' }),
        label: __('License Keys', 'vapt-builder'),
        instructions: __('Manage domain licenses and activation status here.', 'vapt-builder')
      }, [
        el('table', { key: 'table', className: 'wp-list-table widefat fixed striped' }, [
          el('thead', null, el('tr', null, [
            el('th', null, __('Domain', 'vapt-builder')),
            el('th', null, __('License Key', 'vapt-builder')),
            el('th', null, __('Status', 'vapt-builder')),
          ])),
          el('tbody', null, domains.map(d => el('tr', { key: d.id }, [
            el('td', null, d.domain),
            el('td', null, el('code', null, d.license_id || __('No License assigned', 'vapt-builder'))),
            el('td', null, d.license_id ?
              el('span', { style: { color: 'green' } }, __('Active', 'vapt-builder')) :
              el('span', { style: { color: 'red' } }, __('Inactive', 'vapt-builder')))
          ])))
        ])
      ])
    ]);

    const tabs = [
      {
        name: 'features',
        title: __('Feature List', 'vapt-builder'),
        className: 'vaptm-tab-features',
      },
      {
        name: 'license',
        title: __('License Management', 'vapt-builder'),
        className: 'vaptm-tab-license',
      },
      {
        name: 'domains',
        title: __('Domain Features', 'vapt-builder'),
        className: 'vaptm-tab-domains',
      },
      {
        name: 'build',
        title: __('Build Generator', 'vapt-builder'),
        className: 'vaptm-tab-build',
      },
    ];

    if (error) {
      return el('div', { className: 'vaptm-admin-wrap' }, [
        el('h1', null, __('VAPT Builder Dashboard', 'vapt-builder')),
        el(Notice, { status: 'error', isDismissible: false }, error),
        el(Button, { isSecondary: true, onClick: () => fetchData() }, __('Retry', 'vapt-builder'))
      ]);
    }

    return el('div', { className: 'vaptm-admin-wrap' }, [
      el('h1', null, [
        __('VAPT Builder Dashboard', 'vapt-builder'),
        el('span', { style: { fontSize: '0.5em', marginLeft: '10px', color: '#666', fontWeight: 'normal' } }, `v${vaptmSettings.pluginVersion}`)
      ]),
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
            allFiles,
            hiddenFiles,
            onUpdateHiddenFiles: updateHiddenFiles,
            onSelectFile: (val) => {
              if (window.confirm(__('Changing the feature source will override the current list. Previously implemented features with matching keys will retain their status. Proceed?', 'vapt-builder'))) {
                setSelectedFile(val);
                fetchData(val);
              }
            },
            onUpload: uploadJSON,
            isManageModalOpen,
            setIsManageModalOpen,
            assignees,
            onAssign
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
    if (!container) {
      console.warn('VAPT Builder: Root container #vaptm-admin-root not found.');
      return;
    }

    console.log('VAPT Builder: Starting React mount...');

    if (typeof wp === 'undefined' || !wp.element) {
      console.error('VAPT Builder: WordPress React environment (wp.element) missing!');
      container.innerHTML = '<div class="notice notice-error"><p>Error: WordPress React components failed to load. Please check plugin dependencies.</p></div>';
      return;
    }

    try {
      const root = wp.element.createRoot ? wp.element.createRoot(container) : null;
      if (root) {
        root.render(el(VAPTMAdmin));
      } else {
        wp.element.render(el(VAPTMAdmin), container);
      }
      console.log('VAPT Builder: React app mounted successfully.');

      // Remove the loading notice if present
      const loadingNotice = container.querySelector('.notice-info');
      if (loadingNotice) loadingNotice.remove();

    } catch (err) {
      console.error('VAPT Builder: Mounting exception:', err);
      container.innerHTML = `<div class="notice notice-error"><p>Critical UI Mounting Error: ${err.message}</p></div>`;
    }
  };

  // Expose init globally for diagnostics
  window.vaptmInit = init;

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    console.log('VAPT Builder: Document ready, running init');
    init();
  } else {
    console.log('VAPT Builder: Waiting for DOMContentLoaded');
    document.addEventListener('DOMContentLoaded', init);
  }
})();
