// Editable scaffold copy. These examples are never submitted as published consent documents.
export function consentTemplates(locale: string) {
  const ru = locale.startsWith('ru');
  return [
    { code: 'example.product_updates', title: ru ? 'Новости и обновления продукта' : 'Product news and updates',
      text: ru ? 'Шаблон для [Название проекта]\n\nЯ хочу получать новости продукта, сообщения о новых возможностях и специальных предложениях по указанному мной адресу.\n\nЯ могу изменить решение и отозвать согласие в настройках учётной записи.\n\nКонтакт для вопросов: [Адрес поддержки].' : 'Template for [Project name]\n\nI would like to receive product news, new feature announcements and special offers at the address I provide.\n\nI can change my choice and withdraw consent in my account settings.\n\nContact: [Support address].' },
    { code: 'example.profile_data', title: ru ? 'Использование данных профиля' : 'Use of profile information',
      text: ru ? 'Шаблон для [Название проекта]\n\nЯ разрешаю использовать указанные мной данные профиля для [Описание функции и цели].\n\nСостав данных: [Перечень данных]. Срок использования: [Срок или условие].\n\nЯ могу отозвать согласие в настройках учётной записи. Контакт: [Адрес поддержки].' : 'Template for [Project name]\n\nI allow the profile information I provide to be used for [Feature and purpose].\n\nInformation used: [Data categories]. Use period: [Period or condition].\n\nI can withdraw consent in account settings. Contact: [Support address].' },
  ];
}
