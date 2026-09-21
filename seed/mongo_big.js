const big = db.getSiblingDB('shop_big');

big.orders.drop();
big.customers.drop();
big.products.drop();

const cities = ['Київ', 'Львів', 'Одеса', 'Харків', 'Дніпро', 'Запоріжжя', 'Вінниця', 'Полтава'];
const statuses = ['new', 'paid', 'shipped', 'done'];
const categories = ['Ноутбуки', 'Смартфони', 'Аудіо', 'Периферія', 'Монітори', 'Аксесуари'];
const baseDate = new Date('2026-09-01T12:00:00Z').getTime();

function insertInChunks(collection, documents) {
  for (let i = 0; i < documents.length; i += 5000) {
    collection.insertMany(documents.slice(i, i + 5000));
  }
}

const customers = [];
for (let n = 1; n <= 10000; n++) {
  customers.push({
    _id: n,
    name: 'Клієнт ' + n,
    email: 'user' + n + '@example.com',
    city: cities[n % cities.length],
    createdAt: new Date(baseDate - (n % 1500) * 86400000)
  });
}
insertInChunks(big.customers, customers);

const products = [];
for (let n = 1; n <= 1000; n++) {
  products.push({
    _id: n,
    title: 'Товар ' + n,
    category: categories[n % categories.length],
    price: Math.round((100 + ((n * 37) % 50000) / 100) * 100) / 100,
    stock: (n * 17) % 200
  });
}
insertInChunks(big.products, products);

const orders = [];
for (let n = 1; n <= 100000; n++) {
  const quantity = 1 + (n % 5);
  const price = Math.round((100 + ((n * 37) % 50000) / 100) * 100) / 100;
  const itemCount = 1 + (n % 3);
  const items = [];

  for (let k = 0; k < itemCount; k++) {
    const productId = 1 + ((n + k * 97) % 1000);
    const itemPrice = Math.round((100 + (((n + k * 97) * 37) % 50000) / 100) * 100) / 100;
    items.push({
      productId: productId,
      title: 'Товар ' + productId,
      price: itemPrice,
      quantity: 1 + ((n + k) % 3)
    });
  }

  const total = items.reduce((sum, item) => sum + item.price * item.quantity, 0);

  orders.push({
    _id: n,
    customerId: 1 + (n % 10000),
    status: statuses[n % statuses.length],
    items: items,
    total: Math.round(total * 100) / 100,
    orderedAt: new Date(baseDate - (n % 1095) * 86400000 + (n % 86400) * 1000)
  });

  if (orders.length === 5000) {
    insertInChunks(big.orders, orders.splice(0, orders.length));
  }
}
insertInChunks(big.orders, orders);

print('customers=' + big.customers.countDocuments());
print('products=' + big.products.countDocuments());
print('orders=' + big.orders.countDocuments());
