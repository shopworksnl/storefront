import { Component, Mixin } from 'src/core/shopware';
import EntityCollection from 'src/core/data-new/entity-collection.data';
import Criteria from 'src/core/data-new/criteria.data';
import utils from 'src/core/service/util.service';
import LocalStore from 'src/core/data/LocalStore';
import template from './sw-seo-url-template-card.html.twig';
import './sw-seo-url-template-card.scss';

Component.register('sw-seo-url-template-card', {
    template,

    inject: ['seoUrlTemplateService', 'repositoryFactory', 'context'],

    mixins: [Mixin.getByName('notification')],


    data() {
        return {
            defaultSeoUrlTemplates: null,
            seoUrlTemplates: null,
            isLoading: true,
            debouncedPreviews: {},
            previewLoadingStates: {},
            errorMessages: {},
            previews: {},
            variableStores: {},
            seoUrlTemplateRepository: {},
            salesChannelId: null
        };
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.seoUrlTemplateRepository = this.repositoryFactory.create('seo_url_template');
            this.seoUrlTemplates = new EntityCollection(
                this.seoUrlTemplateRepository.route,
                this.seoUrlTemplateRepository.schema.entity,
                this.context, new Criteria()
            );

            this.defaultSeoUrlTemplates = new EntityCollection(
                this.seoUrlTemplateRepository.route,
                this.seoUrlTemplateRepository.schema.entity,
                this.context, new Criteria()
            );

            this.fetchSeoUrlTemplates();
        },
        fetchSeoUrlTemplates(salesChannelId = null) {
            const criteria = new Criteria();

            if (!salesChannelId) {
                salesChannelId = null;
            }
            criteria.addFilter(Criteria.equals('salesChannelId', salesChannelId));

            this.isLoading = true;

            this.seoUrlTemplateRepository.search(criteria, this.context).then((response) => {
                response.forEach(entity => {
                    if (!this.seoUrlTemplates.has(entity.id)) {
                        this.seoUrlTemplates.add(entity);
                    }
                });

                if (!salesChannelId) {
                    // Save the defaults as blueprint for creating dynamically new entities
                    response.forEach(entity => {
                        if (!this.defaultSeoUrlTemplates.has(entity)) {
                            this.defaultSeoUrlTemplates.add(entity);
                        }
                    });
                } else {
                    this.createSeoUrlTemplatesFromDefaultRoutes(salesChannelId);
                }
                this.isLoading = false;

                this.seoUrlTemplates.forEach(seoUrlTemplate => {
                    // Fetch preview / validate seo url template if not done yet

                    if (!seoUrlTemplate.isNew() && !this.previews.hasOwnProperty(seoUrlTemplate.id)) {
                        this.fetchSeoUrlPreview(seoUrlTemplate);
                    }
                    // Create stores for the possible variables
                    if (!this.variableStores.hasOwnProperty(seoUrlTemplate.id)) {
                        this.seoUrlTemplateService.getContext(seoUrlTemplate).then(data => {
                            this.createVariablesStore(seoUrlTemplate.id, data);
                        });
                    }
                });
            });
        },
        createSeoUrlTemplatesFromDefaultRoutes(salesChannelId) {
            // Iterate over the default seo url templates and create new entities for the actual sales channel
            // if they do not exist
            this.defaultSeoUrlTemplates.forEach(defaultEntity => {
                const entityAlreadyExists = Object.values(this.seoUrlTemplates).some((entity) => {
                    return entity.routeName === defaultEntity.routeName && entity.salesChannelId === salesChannelId;
                });

                if (!entityAlreadyExists) {
                    const entity = this.seoUrlTemplateRepository.create(this.context);
                    entity.routeName = defaultEntity.routeName;
                    entity.salesChannelId = salesChannelId;
                    entity.entityName = defaultEntity.entityName;
                    this.seoUrlTemplates.add(entity);
                }
            });
        },
        createVariablesStore(id, data) {
            const storeOptions = [];

            Object.keys(data).forEach((property) => {
                storeOptions.push({ id: property, name: `${property}` });
            });

            this.$set(this.variableStores, id, new LocalStore(storeOptions));
        },
        getVariablesStore(id) {
            if (this.variableStores.hasOwnProperty(id)) {
                return this.variableStores[id];
            }
            return false;
        },
        getLabel(seoUrlTemplate) {
            const routeName = seoUrlTemplate.routeName.replace(/\./g, '-');
            if (this.$te(`sw-seo-url-template-card.routeNames.${routeName}`)) {
                return this.$tc(`sw-seo-url-template-card.routeNames.${routeName}`);
            }

            return seoUrlTemplate.routeName;
        },
        getPlaceholder(seoUrlTemplate) {
            if (!seoUrlTemplate.salesChannelId) {
                return '';
            }

            const defaultEntity = Object.values(this.defaultSeoUrlTemplates).find(entity => {
                return entity.routeName === seoUrlTemplate.routeName;
            });

            return defaultEntity.template;
        },
        onClickSave() {
            const hasError = Object.keys(this.errorMessages).some((key) => {
                return this.errorMessages[key] !== null;
            });

            if (hasError) {
                this.createSaveErrorNotification();
                return;
            }

            const removalPromises = [];
            this.seoUrlTemplates.forEach(seoUrlTemplate => {
                if (!seoUrlTemplate.template) {
                    removalPromises.push(this.seoUrlTemplateService.getDefault(seoUrlTemplate.routeName).then(response => {
                        seoUrlTemplate.template = response.defaultTemplate;
                        return Promise.resolve();
                    }));
                }
            });

            Promise.all(removalPromises).then( () => {
                this.seoUrlTemplateRepository.sync(this.seoUrlTemplates, this.context);

                this.fetchSeoUrlTemplates(this.salesChannelId);

                this.createSaveSuccessNotification();
            }).catch( () => {
                this.createSaveErrorNotification();
            });
        },
        createSaveErrorNotification() {
            const titleSaveSuccess = this.$tc('sw-seo-url-template-card.general.titleSaveError');
            const messageSaveSuccess = this.$tc('sw-seo-url-template-card.general.messageSaveError');

            this.createNotificationError({
                title: titleSaveSuccess,
                message: messageSaveSuccess
            });
        },
        createSaveSuccessNotification() {
            const titleSaveSuccess = this.$tc('sw-seo-url-template-card.general.titleSaveSuccess');
            const messageSaveSuccess = this.$tc('sw-seo-url-template-card.general.messageSaveSuccess');

            this.createNotificationSuccess({
                title: titleSaveSuccess,
                message: messageSaveSuccess
            });
        },

        onSelectInput(propertyName, entity) {
            const templateValue = entity.template ? (`${entity.template}/`) : '';
            entity.template = `${templateValue}{{ ${propertyName} }}`;
            this.fetchSeoUrlPreview(entity);

            const selectComponent = this.$refs[`select-${entity.id}`][0];
            selectComponent.loadSelected();
        },
        onInput(entity) {
            this.debouncedPreviewSeoUrlTemplate(entity);
        },
        debouncedPreviewSeoUrlTemplate(entity) {
            if (!this.debouncedPreviews[entity.id]) {
                this.debouncedPreviews[entity.id] = utils.debounce(() => {
                    if (entity.template && entity.template !== '') {
                        this.fetchSeoUrlPreview(entity);
                    } else {
                        this.$set(this.errorMessages, entity.id, null);
                    }
                }, 400);
            } else {
                this.$set(this.errorMessages, entity.id, null);
            }

            this.debouncedPreviews[entity.id]();
        },
        fetchSeoUrlPreview(entity) {
            this.$set(this.previewLoadingStates, entity.id, true);
            this.seoUrlTemplateService.preview(entity).then((response) => {
                this.$set(this.previews, entity.id, response);
                if (response.length < 1) {
                    this.$set(
                        this.errorMessages,
                        entity.id,
                        this.$tc('sw-seo-url-template-card.general.tooltipInvalidTemplate')
                    );
                } else {
                    this.$set(this.errorMessages, entity.id, null);
                }
                this.previewLoadingStates[entity.id] = false;
            }).catch(err => {
                this.$set(this.errorMessages, entity.id, err.response.data.errors[0].detail);
                this.$set(this.previews, entity.id, []);
                this.previewLoadingStates[entity.id] = false;
            });
        },
        onSalesChannelChanged(salesChannelId) {
            this.salesChannelId = salesChannelId;
            this.fetchSeoUrlTemplates(salesChannelId);
        }
    }
});
