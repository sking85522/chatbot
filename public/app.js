let allProducts = [];
let cart = JSON.parse(localStorage.getItem('fk_cart') || '[]');
let currentCategory = 'all';

document.addEventListener('DOMContentLoaded', () => {
    fetchProducts();
    updateCartCount();
    setupEventListeners();
});

async function fetchProducts() {
    try {
        const res = await fetch('products.json');
        allProducts = await res.json();
        renderProducts(allProducts);
        renderBrands(allProducts);
    } catch (e) {
        console.error('Failed to load products', e);
    }
}

function renderProducts(products) {
    const grid = document.getElementById('productGrid');
    const countEl = document.getElementById('productCount');
    if (!grid) return;

    grid.innerHTML = '';
    if (countEl) countEl.textContent = `${products.length} Items`;

    if (products.length === 0) {
        grid.innerHTML = `
            <div style="grid-column: 1 / -1; padding: 40px; text-align: center; background: #fff;">
                <div style="font-size: 40px; margin-bottom: 12px;">🔍</div>
                <h3>No products found!</h3>
                <p style="color: #878787; font-size: 14px; margin-top: 6px;">Try adjusting your search or category filter.</p>
            </div>
        `;
        return;
    }

    products.forEach(p => {
        const card = document.createElement('div');
        card.className = 'fk-product-card';
        card.onclick = (e) => {
            if (!e.target.classList.contains('fk-add-cart-btn')) {
                openProductModal(p.id);
            }
        };

        card.innerHTML = `
            ${p.badge ? `<span class="fk-badge">${p.badge}</span>` : ''}
            <img src="${p.image}" alt="${escapeHtml(p.title)}" class="fk-product-img" loading="lazy">
            <div>
                <h4 class="fk-product-title" title="${escapeHtml(p.title)}">${escapeHtml(p.title)}</h4>
                <div class="fk-rating-row">
                    <span class="fk-rating-pill">${p.rating} ★</span>
                    <span class="fk-reviews-count">(${p.reviewsCount.toLocaleString()})</span>
                </div>
                <div class="fk-price-row">
                    <span class="fk-price">₹${p.price.toLocaleString()}</span>
                    <span class="fk-mrp">₹${p.mrp.toLocaleString()}</span>
                    <span class="fk-discount">${p.discount}</span>
                </div>
            </div>
            <button class="fk-add-cart-btn" onclick="addToCart('${p.id}', event)">Add to Cart</button>
        `;
        grid.appendChild(card);
    });
}

function renderBrands(products) {
    const container = document.getElementById('brandFilters');
    if (!container) return;

    const brands = [...new Set(products.map(p => p.brand))];
    container.innerHTML = '';

    brands.forEach(b => {
        const label = document.createElement('label');
        label.className = 'fk-filter-option';
        label.innerHTML = `
            <input type="checkbox" value="${escapeHtml(b)}" onchange="filterProducts()">
            <span>${escapeHtml(b)}</span>
        `;
        container.appendChild(label);
    });
}

function filterCategory(cat, el) {
    currentCategory = cat;
    document.querySelectorAll('.fk-cat-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');

    const heading = document.getElementById('categoryHeading');
    if (heading) {
        heading.textContent = cat === 'all' ? 'All Products' : `${cat} Collection`;
    }

    filterProducts();
}

function filterProducts() {
    const searchVal = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const selectedBrands = Array.from(document.querySelectorAll('#brandFilters input:checked')).map(i => i.value);
    const selectedRating = document.querySelector('input[name="ratingFilter"]:checked')?.value || 0;

    let filtered = allProducts;

    // 1. Category Filter
    if (currentCategory !== 'all') {
        filtered = filtered.filter(p => p.category.toLowerCase() === currentCategory.toLowerCase());
    }

    // 2. Search Filter
    if (searchVal) {
        filtered = filtered.filter(p => 
            p.title.toLowerCase().includes(searchVal) || 
            p.description.toLowerCase().includes(searchVal) ||
            p.brand.toLowerCase().includes(searchVal) ||
            p.category.toLowerCase().includes(searchVal)
        );
    }

    // 3. Brand Filter
    if (selectedBrands.length > 0) {
        filtered = filtered.filter(p => selectedBrands.includes(p.brand));
    }

    // 4. Rating Filter
    if (selectedRating > 0) {
        filtered = filtered.filter(p => p.rating >= parseFloat(selectedRating));
    }

    renderProducts(filtered);
}

function addToCart(productId, e) {
    if (e) e.stopPropagation();
    const prod = allProducts.find(p => p.id === productId);
    if (!prod) return;

    const existing = cart.find(i => i.id === productId);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({ ...prod, qty: 1 });
    }

    localStorage.setItem('fk_cart', JSON.stringify(cart));
    updateCartCount();
    showToast(`Added ${prod.title.substring(0, 25)}... to Cart!`);
}

function updateCartCount() {
    const totalCount = cart.reduce((acc, i) => acc + i.qty, 0);
    const badge = document.getElementById('cartBadge');
    if (badge) badge.textContent = totalCount;
}

function openProductModal(productId) {
    const p = allProducts.find(item => item.id === productId);
    if (!p) return;

    const modal = document.getElementById('quickViewModal');
    const body = document.getElementById('quickViewBody');
    if (!modal || !body) return;

    body.innerHTML = `
        <div style="display: flex; gap: 24px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px; text-align: center;">
                <img src="${p.image}" alt="${escapeHtml(p.title)}" style="max-width: 100%; max-height: 320px; object-fit: contain;">
                <div style="margin-top: 16px; display: flex; gap: 10px;">
                    <button class="fk-add-cart-btn" style="flex: 1; padding: 12px;" onclick="addToCart('${p.id}'); closeModal('quickViewModal'); openCartModal();">Buy Now</button>
                    <button class="fk-add-cart-btn" style="flex: 1; padding: 12px; background: #ff9f00;" onclick="addToCart('${p.id}'); closeModal('quickViewModal');">Add to Cart</button>
                </div>
            </div>
            <div style="flex: 1.5; min-width: 300px;">
                <span style="font-size: 12px; color: #878787; text-transform: uppercase;">${p.category} &bull; ${p.subcategory} &bull; ${p.brand}</span>
                <h2 style="font-size: 20px; font-weight: 700; margin: 6px 0 10px;">${escapeHtml(p.title)}</h2>
                <div class="fk-rating-row">
                    <span class="fk-rating-pill">${p.rating} ★</span>
                    <span class="fk-reviews-count">${p.reviewsCount.toLocaleString()} Ratings & Reviews</span>
                </div>
                <div class="fk-price-row" style="margin: 12px 0 16px;">
                    <span class="fk-price" style="font-size: 24px;">₹${p.price.toLocaleString()}</span>
                    <span class="fk-mrp" style="font-size: 15px;">₹${p.mrp.toLocaleString()}</span>
                    <span class="fk-discount" style="font-size: 15px;">${p.discount}</span>
                </div>
                <p style="font-size: 14px; line-height: 1.5; color: #555; margin-bottom: 16px;">${escapeHtml(p.description)}</p>
                <h4 style="font-size: 13px; text-transform: uppercase; color: #878787; margin-bottom: 8px;">Key Specifications</h4>
                <ul style="padding-left: 18px; font-size: 13.5px; line-height: 1.6; color: #333;">
                    ${p.specs.map(s => `<li>${escapeHtml(s)}</li>`).join('')}
                </ul>
            </div>
        </div>
    `;

    modal.style.display = 'flex';
}

function openCartModal() {
    const modal = document.getElementById('cartModal');
    const list = document.getElementById('cartItemsList');
    const totalEl = document.getElementById('cartTotalAmount');
    if (!modal || !list) return;

    list.innerHTML = '';
    let total = 0;

    if (cart.length === 0) {
        list.innerHTML = `
            <div style="padding: 40px; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 12px;">🛒</div>
                <h3>Your cart is empty!</h3>
                <p style="color: #878787; font-size: 14px; margin-top: 6px;">Add items to it now.</p>
            </div>
        `;
        if (totalEl) totalEl.textContent = '₹0';
    } else {
        cart.forEach(item => {
            const itemTotal = item.price * item.qty;
            total += itemTotal;

            const row = document.createElement('div');
            row.style.cssText = 'display: flex; gap: 16px; padding: 14px 0; border-bottom: 1px solid #e0e0e0; align-items: center;';
            row.innerHTML = `
                <img src="${item.image}" alt="${escapeHtml(item.title)}" style="width: 60px; height: 60px; object-fit: contain;">
                <div style="flex: 1;">
                    <div style="font-size: 14px; font-weight: 600;">${escapeHtml(item.title)}</div>
                    <div style="font-size: 13px; color: #878787; margin-top: 4px;">₹${item.price.toLocaleString()} x ${item.qty}</div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button onclick="changeQty('${item.id}', -1)" style="width: 26px; height: 26px; border: 1px solid #ccc; background: #fff; cursor: pointer;">-</button>
                    <span style="font-weight: 600; font-size: 13px;">${item.qty}</span>
                    <button onclick="changeQty('${item.id}', 1)" style="width: 26px; height: 26px; border: 1px solid #ccc; background: #fff; cursor: pointer;">+</button>
                    <button onclick="removeFromCart('${item.id}')" style="background: none; border: none; color: #d32f2f; margin-left: 8px; cursor: pointer;">🗑️</button>
                </div>
            `;
            list.appendChild(row);
        });
        if (totalEl) totalEl.textContent = `₹${total.toLocaleString()}`;
    }

    modal.style.display = 'flex';
}

function changeQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    item.qty += delta;
    if (item.qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    }
    localStorage.setItem('fk_cart', JSON.stringify(cart));
    updateCartCount();
    openCartModal();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    localStorage.setItem('fk_cart', JSON.stringify(cart));
    updateCartCount();
    openCartModal();
}

function checkoutOrder() {
    if (cart.length === 0) {
        alert('Your cart is empty!');
        return;
    }
    const total = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
    const confirmBuy = confirm(`Confirm order for ₹${total.toLocaleString()}?\n(Delivery to saved address. Cash on Delivery / UPI)`);
    if (confirmBuy) {
        alert('🎉 Order Placed Successfully!\nThank you for shopping with ShopKart.');
        cart = [];
        localStorage.removeItem('fk_cart');
        updateCartCount();
        closeModal('cartModal');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'none';
}

function setupEventListeners() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', () => filterProducts());
    }
}

function showToast(msg) {
    let toast = document.getElementById('fkToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'fkToast';
        toast.style.cssText = 'position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%); background: #212121; color: #fff; padding: 10px 20px; border-radius: 4px; font-size: 13px; z-index: 99999; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transition: opacity 0.3s;';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.display = 'block';
    toast.style.opacity = '1';
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.style.display = 'none', 300);
    }, 2200);
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

