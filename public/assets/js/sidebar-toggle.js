(() => {
  const mobileQuery = window.matchMedia('(max-width: 1100px)');
  const mobileStorageKey = 'dashboard_sidebar_open_mobile';
  const desktopStorageKey = 'dashboard_sidebar_collapsed_desktop';
  const groupsStorageKey = 'dashboard_sidebar_groups_state';
  const assistantPositionStorageKey = 'dashboard_guardian_position';
  const groupAnimationTimers = new WeakMap();
  let lastGroupNonClickToggleAt = 0;
  const appBasePath = (() => {
    const raw = document.body?.dataset?.appBasePath || '';
    if (!raw || raw === '/') {
      return '';
    }

    return raw.replace(/\/+$/, '');
  })();

  const getShell = () => document.querySelector('.db-shell');

  const isOpen = () => {
    const shell = getShell();
    return !!shell && shell.classList.contains('is-sidebar-open');
  };

  const isDesktopCollapsed = () => {
    const shell = getShell();
    return !!shell && shell.classList.contains('is-sidebar-collapsed');
  };

  const syncToggleState = () => {
    const expanded = mobileQuery.matches
      ? (isOpen() ? 'true' : 'false')
      : (isDesktopCollapsed() ? 'false' : 'true');
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
      toggle.setAttribute('aria-expanded', expanded);
    });
  };

  const closeSidebar = () => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.remove('is-sidebar-open');
    try {
      localStorage.setItem(mobileStorageKey, '0');
    } catch (error) {
      // no-op
    }
    syncToggleState();
  };

  const openSidebar = () => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.add('is-sidebar-open');
    try {
      localStorage.setItem(mobileStorageKey, '1');
    } catch (error) {
      // no-op
    }
    syncToggleState();
  };

  const setDesktopCollapsed = (collapsed, persist = true) => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.toggle('is-sidebar-collapsed', collapsed);
    if (persist) {
      try {
        localStorage.setItem(desktopStorageKey, collapsed ? '1' : '0');
      } catch (error) {
        // no-op
      }
    }

    syncToggleState();
  };

  const toggleSidebar = () => {
    if (mobileQuery.matches) {
      if (isOpen()) {
        closeSidebar();
        return;
      }

      openSidebar();
      return;
    }

    setDesktopCollapsed(!isDesktopCollapsed());
  };

  const handleResponsiveReset = () => {
    if (!mobileQuery.matches) {
      closeSidebar();
    }

    applyPersistedState();
  };

  const applyPersistedState = () => {
    const shell = getShell();
    if (!shell) {
      document.documentElement.classList.remove('db-sidebar-collapsed-initial');
      return;
    }

    if (!mobileQuery.matches) {
      closeSidebar();
      let shouldCollapse = false;
      try {
        shouldCollapse = localStorage.getItem(desktopStorageKey) === '1';
      } catch (error) {
        shouldCollapse = false;
      }

      setDesktopCollapsed(shouldCollapse, false);
      shell.classList.add('sidebar-state-ready');
      document.documentElement.classList.remove('db-sidebar-collapsed-initial');
      return;
    }

    shell.classList.remove('is-sidebar-collapsed');

    let shouldOpen = false;
    try {
      shouldOpen = localStorage.getItem(mobileStorageKey) === '1';
    } catch (error) {
      shouldOpen = false;
    }

    if (shouldOpen) {
      openSidebar();
    } else {
      closeSidebar();
    }

    shell.classList.add('sidebar-state-ready');
    document.documentElement.classList.remove('db-sidebar-collapsed-initial');
  };

  const readGroupsState = () => {
    try {
      const raw = localStorage.getItem(groupsStorageKey);
      return raw ? JSON.parse(raw) : {};
    } catch (error) {
      return {};
    }
  };

  const writeGroupsState = (state) => {
    try {
      localStorage.setItem(groupsStorageKey, JSON.stringify(state));
    } catch (error) {
      // no-op
    }
  };

  const setGroupOpen = (toggle, items, open, animate = false) => {
    const groupBlock = toggle.closest('[data-menu-group]');
    if (groupBlock) {
      groupBlock.classList.toggle('is-open', open);
    }

    const pendingTimer = groupAnimationTimers.get(items);
    if (pendingTimer) {
      window.clearTimeout(pendingTimer);
      groupAnimationTimers.delete(items);
    }

    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

    const prefersReducedMotion =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!animate || prefersReducedMotion) {
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';

      if (open) {
        items.removeAttribute('hidden');
        return;
      }

      items.setAttribute('hidden', 'hidden');
      return;
    }

    items.style.overflow = 'hidden';
    items.style.transition = 'max-height 180ms ease, opacity 180ms ease';

    if (open) {
      items.removeAttribute('hidden');
      items.style.maxHeight = '0px';
      items.style.opacity = '0';

      requestAnimationFrame(() => {
        items.style.maxHeight = `${items.scrollHeight}px`;
        items.style.opacity = '1';
      });

      const timerId = window.setTimeout(() => {
        items.style.maxHeight = '';
        items.style.opacity = '';
        items.style.overflow = '';
        groupAnimationTimers.delete(items);
      }, 200);
      groupAnimationTimers.set(items, timerId);

      return;
    }

    if (items.hasAttribute('hidden')) {
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';
      return;
    }

    items.style.maxHeight = `${items.scrollHeight}px`;
    items.style.opacity = '1';

    requestAnimationFrame(() => {
      items.style.maxHeight = '0px';
      items.style.opacity = '0';
    });

    const timerId = window.setTimeout(() => {
      items.setAttribute('hidden', 'hidden');
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';
      groupAnimationTimers.delete(items);
    }, 200);
    groupAnimationTimers.set(items, timerId);
  };

  const applyGroupState = () => {
    const normalizePath = (value) => {
      const path = String(value || '').replace(/\/+$/, '');
      return path === '' ? '/' : path;
    };
    const homeHref = appBasePath || '/';
    const homeLink = document.querySelector(`.db-menu > a.db-menu-item[href="${homeHref}"]`);
    const currentPath = normalizePath(window.location.pathname);
    const expectedHomePath = normalizePath(homeHref);
    let isHomeRoute = currentPath === expectedHomePath;

    if (!isHomeRoute && homeLink) {
      const href = homeLink.getAttribute('href') || '';
      const homePath = normalizePath(new URL(href, window.location.origin).pathname);
      isHomeRoute = currentPath === homePath || currentPath === `${homePath}/index.php`;
    }

    const isHomeLinkActive = Boolean(
      document.querySelector(`.db-menu > a.db-menu-item.is-active[href="${homeHref}"]`)
    );
    const isHomeActive = isHomeRoute || isHomeLinkActive;

    if (isHomeActive) {
      document.querySelectorAll('[data-menu-group]').forEach((groupBlock) => {
        const toggle = groupBlock.querySelector('[data-menu-group-toggle]');
        const items = groupBlock.querySelector('[data-menu-group-items]');
        if (!toggle || !items) {
          return;
        }

        setGroupOpen(toggle, items, false);
      });
      writeGroupsState({});
      return;
    }

    const state = readGroupsState();
    const persistedOpenKey = typeof state.openKey === 'string' ? state.openKey : null;
    let firstOpenKey = null;

    document.querySelectorAll('[data-menu-group]').forEach((groupBlock, index) => {
      const toggle = groupBlock.querySelector('[data-menu-group-toggle]');
      const items = groupBlock.querySelector('[data-menu-group-items]');
      if (!toggle || !items) {
        return;
      }

      const groupKey = toggle.getAttribute('data-group-key') || `group-${index}`;
      if (persistedOpenKey !== null) {
        const shouldOpen = persistedOpenKey === groupKey;
        if (shouldOpen) {
          firstOpenKey = groupKey;
        }
        setGroupOpen(toggle, items, shouldOpen);
        return;
      }

      if (Object.prototype.hasOwnProperty.call(state, groupKey)) {
        const shouldOpen = state[groupKey] === '1';
        if (shouldOpen && firstOpenKey === null) {
          firstOpenKey = groupKey;
          setGroupOpen(toggle, items, true);
          return;
        }

        setGroupOpen(toggle, items, false);
      }
    });

    if (firstOpenKey !== null) {
      writeGroupsState({ openKey: firstOpenKey });
    }
  };

  const setSingleOpenGroup = (groupToggle, open, animate = false) => {
    const allGroups = document.querySelectorAll('[data-menu-group]');
    const state = readGroupsState();
    let openKey = null;

    allGroups.forEach((groupBlock, index) => {
      const toggle = groupBlock.querySelector('[data-menu-group-toggle]');
      const items = groupBlock.querySelector('[data-menu-group-items]');
      if (!toggle || !items) {
        return;
      }

      const groupKey = toggle.getAttribute('data-group-key') || `group-${index}`;
      const isTarget = toggle === groupToggle;
      const shouldOpen = isTarget ? open : false;

      if (shouldOpen) {
        openKey = groupKey;
      }

      setGroupOpen(toggle, items, shouldOpen, animate);
    });

    if (openKey) {
      state.openKey = openKey;
    } else {
      delete state.openKey;
    }

    Object.keys(state).forEach((key) => {
      if (key !== 'openKey') {
        delete state[key];
      }
    });

    writeGroupsState(state);
  };

  const resolveEventTargetElement = (event) => {
    const eventTarget = event.target;
    if (eventTarget instanceof Element) {
      return eventTarget;
    }

    if (eventTarget && eventTarget.parentElement instanceof Element) {
      return eventTarget.parentElement;
    }

    return null;
  };

  const toggleGroup = (groupToggle) => {
    const groupBlock = groupToggle.closest('[data-menu-group]');
    const groupItems = groupBlock ? groupBlock.querySelector('[data-menu-group-items]') : null;
    if (!groupItems) {
      return;
    }

    const open = groupToggle.getAttribute('aria-expanded') !== 'true';
    setSingleOpenGroup(groupToggle, open, mobileQuery.matches);
  };

  const initAccountAvatarPreview = (root = document) => {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    const forms = scope.querySelectorAll('form.db-avatar-form');

    forms.forEach((form) => {
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      if (form.dataset.avatarPreviewBound === '1') {
        return;
      }

      const preview = form.querySelector('[data-account-avatar-preview]');
      const badge = form.querySelector('[data-account-avatar-badge]');
      const uploadInput = form.querySelector('[data-avatar-upload-input]');
      const optionRadios = form.querySelectorAll('[data-avatar-option-radio]');

      if (!(preview instanceof HTMLImageElement)) {
        return;
      }

      if (uploadInput instanceof HTMLInputElement) {
        const applyPreviewFromFile = () => {
          const selectedFile = uploadInput.files && uploadInput.files[0] ? uploadInput.files[0] : null;
          if (!selectedFile) {
            return;
          }

          const previousObjectUrl = preview.dataset.objectUrl || '';
          if (previousObjectUrl) {
            URL.revokeObjectURL(previousObjectUrl);
            preview.dataset.objectUrl = '';
          }

          if (typeof FileReader === 'function') {
            const reader = new FileReader();
            reader.onload = () => {
              if (typeof reader.result === 'string' && reader.result !== '') {
                preview.src = reader.result;
              }
            };
            reader.readAsDataURL(selectedFile);
          } else {
            const objectUrl = URL.createObjectURL(selectedFile);
            preview.src = objectUrl;
            preview.dataset.objectUrl = objectUrl;
          }

          if (badge) {
            badge.textContent = 'Pré-visualização';
          }
        };

        uploadInput.addEventListener('change', applyPreviewFromFile);
        uploadInput.addEventListener('input', applyPreviewFromFile);
      }

      optionRadios.forEach((radio) => {
        if (!(radio instanceof HTMLInputElement)) {
          return;
        }

        radio.addEventListener('change', () => {
          if (!radio.checked) {
            return;
          }

          const label = radio.closest('.db-avatar-option');
          const optionImage = label ? label.querySelector('[data-avatar-option-image]') : null;
          if (!(optionImage instanceof HTMLImageElement)) {
            return;
          }

          const previousObjectUrl = preview.dataset.objectUrl || '';
          if (previousObjectUrl) {
            URL.revokeObjectURL(previousObjectUrl);
            preview.dataset.objectUrl = '';
          }

          preview.src = optionImage.src;
          if (badge) {
            badge.textContent = 'Padrão';
          }
        });
      });

      form.dataset.avatarPreviewBound = '1';
    });
  };

  const initGuardianImageZoom = (root = document) => {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    const zoomWrappers = scope.querySelectorAll('.db-guardian-zoom');

    zoomWrappers.forEach((wrapper) => {
      if (!(wrapper instanceof HTMLElement)) {
        return;
      }

      if (wrapper.dataset.guardianZoomBound === '1') {
        return;
      }

      const updateOrigin = (clientX, clientY) => {
        const rect = wrapper.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) {
          return;
        }

        const localX = Math.max(0, Math.min(rect.width, clientX - rect.left));
        const localY = Math.max(0, Math.min(rect.height, clientY - rect.top));
        const xPercent = (localX / rect.width) * 100;
        const yPercent = (localY / rect.height) * 100;
        wrapper.style.setProperty('--guardian-zoom-x', `${xPercent}%`);
        wrapper.style.setProperty('--guardian-zoom-y', `${yPercent}%`);
      };

      wrapper.addEventListener('mousemove', (event) => {
        updateOrigin(event.clientX, event.clientY);
      });

      wrapper.addEventListener('mouseenter', (event) => {
        updateOrigin(event.clientX, event.clientY);
      });

      wrapper.addEventListener('mouseleave', () => {
        wrapper.style.setProperty('--guardian-zoom-x', '50%');
        wrapper.style.setProperty('--guardian-zoom-y', '50%');
      });

      wrapper.dataset.guardianZoomBound = '1';
    });
  };

  const initFloatingAssistantDrag = () => {
    const assistant = document.querySelector('.db-floating-assistant');
    if (!(assistant instanceof HTMLAnchorElement)) {
      return;
    }

    if (assistant.dataset.dragBound === '1') {
      return;
    }

    const bubble = document.querySelector('[data-assistant-bubble]');
    const viewportMargin = 8;
    let pointerId = null;
    let startX = 0;
    let startY = 0;
    let originX = 0;
    let originY = 0;
    let dragging = false;
    let dragMoved = false;

    const getAssistantSize = () => {
      const rect = assistant.getBoundingClientRect();
      const width = rect.width > 0 ? rect.width : assistant.offsetWidth;
      const height = rect.height > 0 ? rect.height : assistant.offsetHeight;
      return {
        width: width > 0 ? width : 120,
        height: height > 0 ? height : 120,
      };
    };

    const clampPosition = (x, y) => {
      const { width, height } = getAssistantSize();
      const maxX = Math.max(viewportMargin, window.innerWidth - width - viewportMargin);
      const maxY = Math.max(viewportMargin, window.innerHeight - height - viewportMargin);

      return {
        x: Math.min(maxX, Math.max(viewportMargin, x)),
        y: Math.min(maxY, Math.max(viewportMargin, y)),
      };
    };

    const updateBubblePosition = (x, y) => {
      if (!(bubble instanceof HTMLElement)) {
        return;
      }

      const { width, height } = getAssistantSize();
      const bubbleWidth = bubble.offsetWidth > 0 ? bubble.offsetWidth : 320;
      const bubbleHeight = bubble.offsetHeight > 0 ? bubble.offsetHeight : 120;
      const gap = 12;

      let bubbleX = x - bubbleWidth - gap;
      if (bubbleX < viewportMargin) {
        bubbleX = x + width + gap;
      }

      const maxBubbleX = Math.max(viewportMargin, window.innerWidth - bubbleWidth - viewportMargin);
      bubbleX = Math.min(maxBubbleX, Math.max(viewportMargin, bubbleX));

      let bubbleY = y + (height * 0.5) - (bubbleHeight * 0.5);
      const maxBubbleY = Math.max(viewportMargin, window.innerHeight - bubbleHeight - viewportMargin);
      bubbleY = Math.min(maxBubbleY, Math.max(viewportMargin, bubbleY));

      bubble.style.left = `${bubbleX}px`;
      bubble.style.top = `${bubbleY}px`;
      bubble.style.right = 'auto';
      bubble.style.bottom = 'auto';
    };

    const applyPosition = (x, y, persist = true) => {
      const next = clampPosition(x, y);
      assistant.style.left = `${next.x}px`;
      assistant.style.top = `${next.y}px`;
      assistant.style.right = 'auto';
      assistant.style.bottom = 'auto';
      assistant.dataset.posX = String(next.x);
      assistant.dataset.posY = String(next.y);

      updateBubblePosition(next.x, next.y);

      if (persist) {
        try {
          localStorage.setItem(assistantPositionStorageKey, JSON.stringify(next));
        } catch (error) {
          // no-op
        }
      }
    };

    const bootstrapPosition = () => {
      let initialX = 0;
      let initialY = 0;

      try {
        const raw = localStorage.getItem(assistantPositionStorageKey);
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed && typeof parsed.x === 'number' && typeof parsed.y === 'number') {
            initialX = parsed.x;
            initialY = parsed.y;
          }
        }
      } catch (error) {
        // no-op
      }

      if (initialX === 0 && initialY === 0) {
        const rect = assistant.getBoundingClientRect();
        initialX = rect.left;
        initialY = rect.top;
      }

      applyPosition(initialX, initialY, false);
    };

    const beginDrag = (event) => {
      if (event.button !== 0 && event.pointerType !== 'touch') {
        return;
      }

      pointerId = event.pointerId;
      dragging = false;
      dragMoved = false;
      startX = event.clientX;
      startY = event.clientY;
      originX = Number.parseFloat(assistant.dataset.posX || '0');
      originY = Number.parseFloat(assistant.dataset.posY || '0');
      assistant.setPointerCapture(pointerId);
    };

    const continueDrag = (event) => {
      if (pointerId === null || event.pointerId !== pointerId) {
        return;
      }

      const deltaX = event.clientX - startX;
      const deltaY = event.clientY - startY;
      const movedDistance = Math.abs(deltaX) + Math.abs(deltaY);

      if (!dragging && movedDistance < 6) {
        return;
      }

      dragging = true;
      dragMoved = true;
      event.preventDefault();
      applyPosition(originX + deltaX, originY + deltaY, false);
    };

    const endDrag = (event) => {
      if (pointerId === null || event.pointerId !== pointerId) {
        return;
      }

      if (assistant.hasPointerCapture(pointerId)) {
        assistant.releasePointerCapture(pointerId);
      }

      if (dragging) {
        event.preventDefault();
        applyPosition(
          Number.parseFloat(assistant.dataset.posX || '0'),
          Number.parseFloat(assistant.dataset.posY || '0'),
          true
        );
      }

      pointerId = null;
      dragging = false;
    };

    assistant.addEventListener('pointerdown', beginDrag);
    assistant.addEventListener('pointermove', continueDrag);
    assistant.addEventListener('pointerup', endDrag);
    assistant.addEventListener('pointercancel', endDrag);

    assistant.addEventListener('click', (event) => {
      if (!dragMoved) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      dragMoved = false;
    });

    window.addEventListener('resize', () => {
      const x = Number.parseFloat(assistant.dataset.posX || '0');
      const y = Number.parseFloat(assistant.dataset.posY || '0');
      applyPosition(x, y, true);
    });

    bootstrapPosition();
    assistant.dataset.dragBound = '1';
  };

  const handleGroupToggleEvent = (event) => {
    const targetElement = resolveEventTargetElement(event);
    if (!targetElement) {
      return false;
    }

    const groupToggle = targetElement.closest('[data-menu-group-toggle]');
    if (!groupToggle) {
      return false;
    }

    event.preventDefault();
    event.stopPropagation();
    toggleGroup(groupToggle);
    return true;
  };

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sidebar-toggle]');
    if (toggle) {
      event.preventDefault();
      toggleSidebar();
      return;
    }

    const backdrop = event.target.closest('[data-sidebar-backdrop]');
    if (backdrop) {
      closeSidebar();
      return;
    }

    const targetElement = resolveEventTargetElement(event);
    const groupToggle = targetElement ? targetElement.closest('[data-menu-group-toggle]') : null;
    if (groupToggle && Date.now() - lastGroupNonClickToggleAt < 500) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (handleGroupToggleEvent(event)) {
      return;
    }

    const passwordToggle = targetElement ? targetElement.closest('[data-password-toggle]') : null;
    if (passwordToggle) {
      event.preventDefault();
      const wrapper = passwordToggle.closest('.db-password-wrap');
      const input = wrapper ? wrapper.querySelector('[data-password-input]') : null;
      if (!input) {
        return;
      }

      const showPassword = input.type === 'password';
      input.type = showPassword ? 'text' : 'password';
      passwordToggle.textContent = showPassword ? 'Ocultar' : 'Mostrar';
      return;
    }

    const menuLink = event.target.closest('.db-menu-item');
    if (menuLink && mobileQuery.matches) {
      closeSidebar();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      closeSidebar();
    }
  });

  document.addEventListener('dragstart', (event) => {
    const targetElement = resolveEventTargetElement(event);
    if (!targetElement) {
      return;
    }

    const floatingAssistant = targetElement.closest('.db-floating-assistant');
    if (floatingAssistant) {
      event.preventDefault();
    }
  });

  if (typeof window.PointerEvent === 'function') {
    document.addEventListener('pointerup', (event) => {
      if (event.pointerType !== 'touch') {
        return;
      }

      if (handleGroupToggleEvent(event)) {
        lastGroupNonClickToggleAt = Date.now();
      }
    });
  } else {
    document.addEventListener('touchend', (event) => {
      if (handleGroupToggleEvent(event)) {
        lastGroupNonClickToggleAt = Date.now();
      }
    }, { passive: false });
  }

  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', handleResponsiveReset);
  } else {
    mobileQuery.addListener(handleResponsiveReset);
  }

  document.body.addEventListener('htmx:afterSwap', () => {
    applyPersistedState();
    applyGroupState();
    initAccountAvatarPreview();
    initFloatingAssistantDrag();
    initGuardianImageZoom();
  });

  applyPersistedState();
  applyGroupState();
  initAccountAvatarPreview();
  initFloatingAssistantDrag();
  initGuardianImageZoom();
})();
