import { useI18n } from 'vue-i18n';
import { i18n } from '@/shared/i18n';

const copy = {
  today: ['Сегодня', 'Today'], calendar: ['Календарь', 'Calendar'], people: ['Люди', 'People'], messages: ['Сообщения', 'Messages'], more: ['Ещё', 'More'],
  morning: ['Доброе утро', 'Good morning'], afternoon: ['Добрый день', 'Good afternoon'], evening: ['Добрый вечер', 'Good evening'],
  notifications: ['Уведомления', 'Notifications'], profile: ['Профиль', 'Profile'], week: ['Выбор даты', 'Choose a date'],
  nextLesson: ['Следующее занятие', 'Next lesson'], noNext: ['Предстоящих занятий нет', 'No upcoming lessons'], now: ['Сейчас', 'Now'], completed: ['Завершено', 'Completed'], cancelled: ['Отменено', 'Cancelled'], scheduled: ['Запланировано', 'Scheduled'],
  openLesson: ['Открыть занятие', 'Open lesson'], participants: ['Список', 'Participants'], materials: ['Материалы', 'Materials'], allEvents: ['Все события', 'All events'], quickActions: ['Быстрые действия', 'Quick actions'], attention: ['Требует внимания', 'Needs attention'], all: ['Все', 'All'],
  currentLesson: ['К текущему занятию', 'Current lesson'], createLesson: ['Создать занятие', 'Create lesson'], createGroup: ['Создать группу', 'Create group'], addStudent: ['Добавить ученика', 'Add student'], uploadMaterials: ['Загрузить материалы', 'Upload materials'], writeMessage: ['Написать сообщение', 'Write message'],
  studentOne: ['{count} ученик', '{count} student'], studentFew: ['{count} ученика', '{count} students'], studentMany: ['{count} учеников', '{count} students'],
  absent: ['Пропуски вчера: {count}', 'Absent yesterday: {count}'], emptyDay: ['На этот день ничего не запланировано', 'Nothing scheduled for this day'],
  loading: ['Загрузка…', 'Loading…'], retry: ['Повторить', 'Retry'], loadMore: ['Загрузить ещё', 'Load more'], error: ['Не удалось загрузить данные. Повторите запрос.', 'Could not load the data. Try again.'],
  denied: ['Нет доступа. Проверьте назначение преподавателя и членство в организации.', 'Access denied. Check your teacher assignment and organization membership.'], notFound: ['Запись не найдена или больше недоступна.', 'This record was not found or is no longer available.'], invalid: ['Проверьте заполненные поля.', 'Check the form fields.'],
  unexpected: ['Сервер вернул некорректный ответ. Обновите страницу.', 'The server returned an invalid response. Refresh the page.'], uncertain: ['Результат отправки неизвестен. Проверьте список перед повторной отправкой.', 'The result is unknown. Check the list before submitting again.'], checkList: ['Проверить список', 'Check the list'],
  organization: ['Организация', 'Organization'], chooseOrganization: ['Выберите организацию', 'Choose an organization'], noOrganizations: ['Нет доступных организаций', 'No available organizations'], noOrganizationsHint: ['Для входа в рабочее пространство необходимо активное членство и назначение преподавателя.', 'An active membership and teacher assignment are required to enter this workspace.'],
  signIn: ['Войти', 'Sign in'], signOut: ['Выйти', 'Sign out'], email: ['Электронная почта', 'Email'], password: ['Пароль', 'Password'], loginTitle: ['Вход в Education', 'Sign in to Education'], loginHint: ['Занятия, ученики и ваш учебный день.', 'Your lessons, students and teaching day.'], forgotPassword: ['Забыли пароль?', 'Forgot password?'],
  mfaTitle: ['Подтвердите вход', 'Verify your sign-in'], code: ['Код из приложения', 'Authenticator code'], recoveryCode: ['Код восстановления', 'Recovery code'], useRecovery: ['Использовать код восстановления', 'Use a recovery code'], useAuthenticator: ['Использовать приложение', 'Use authenticator'], confirm: ['Подтвердить', 'Confirm'], restart: ['Начать заново', 'Start again'],
  changePassword: ['Создайте новый пароль', 'Create a new password'], currentPassword: ['Текущий пароль', 'Current password'], newPassword: ['Новый пароль', 'New password'], repeatPassword: ['Повторите новый пароль', 'Repeat new password'], passwordMismatch: ['Пароли должны совпадать и содержать не менее 12 символов.', 'Passwords must match and contain at least 12 characters.'],
  back: ['Назад', 'Back'], save: ['Сохранить', 'Save'], saved: ['Сохранено', 'Saved'], name: ['Название', 'Name'], group: ['Группа', 'Group'], groups: ['Группы', 'Groups'], students: ['Ученики', 'Students'], colleagues: ['Преподаватели', 'Teachers'], noPeople: ['Учеников пока нет', 'No students yet'], noGroups: ['Групп пока нет', 'No groups yet'],
  choosePerson: ['Выберите участника организации', 'Choose an organization member'], chooseGroup: ['Выберите группу', 'Choose a group'], noCandidates: ['Нет доступных участников. Состав организации настраивает администратор.', 'No available members. An administrator manages the organization roster.'],
  title: ['Название', 'Title'], startsAt: ['Начало', 'Starts at'], endsAt: ['Окончание', 'Ends at'], location: ['Место или ссылка на встречу', 'Location or meeting link'], timezone: ['Часовой пояс', 'Timezone'], invalidTime: ['Проверьте время. Оно должно существовать в выбранном часовом поясе и однозначно определять момент.', 'Check the time. It must exist and be unambiguous in the selected timezone.'],
  event: ['Событие', 'Event'], createEvent: ['Добавить событие', 'Add event'], meeting: ['Встреча', 'Meeting'], birthday: ['День рождения', 'Birthday'], other: ['Другое', 'Other'], kind: ['Тип события', 'Event type'],
  noCurrent: ['Сейчас нет занятия', 'No lesson is in progress'], attendance: ['Посещаемость', 'Attendance'], pending: ['Не отмечено', 'Not recorded'], present: ['Присутствует', 'Present'], absentStatus: ['Отсутствует', 'Absent'], noParticipants: ['Участников пока нет', 'No participants yet'],
  noMaterials: ['Материалов пока нет', 'No materials yet'], file: ['Файл', 'File'], fileHint: ['PDF, текст, PNG, JPEG или WebP. Материалы — до 10 МБ; avatar — до 2 МБ.', 'PDF, text, PNG, JPEG or WebP. Materials: up to 10 MB; avatar: up to 2 MB.'], download: ['Скачать', 'Download'], uploaded: ['Файл загружен', 'File uploaded'],
  noThreads: ['Диалогов пока нет', 'No conversations yet'], noRecipients: ['Нет доступных адресатов. Переписка доступна между преподавателями вашей организации.', 'No recipients available. Messaging is available between teachers in your organization.'], recipient: ['Кому', 'To'], subject: ['Тема', 'Subject'], message: ['Сообщение', 'Message'], send: ['Отправить', 'Send'], unavailableMessage: ['Сообщение недоступно', 'Message unavailable'],
  noNotifications: ['Новых уведомлений нет', 'No notifications'], markRead: ['Прочитано', 'Mark read'], displayName: ['Имя в профиле', 'Profile name'], avatar: ['Фотография профиля', 'Profile photo'], removeAvatar: ['Убрать фотографию', 'Remove photo'], appearance: ['Оформление', 'Appearance'], light: ['Светлая', 'Light'], dark: ['Тёмная', 'Dark'], language: ['Язык', 'Language'], refresh: ['Обновить', 'Refresh'],
} as const;
for (const [locale, index] of [['ru', 0], ['en', 1]] as const) {
  i18n.global.mergeLocaleMessage(locale, { education: Object.fromEntries(Object.entries(copy).map(([key, value]) => [key, value[index]])) });
}
export function useEducationI18n() {
  const { t, locale } = useI18n();
  function students(count: number): string {
    const rule = new Intl.PluralRules(locale.value).select(count);
    return t(`education.${rule === 'one' ? 'studentOne' : rule === 'few' ? 'studentFew' : 'studentMany'}`, { count });
  }
  return { t, locale, students };
}
