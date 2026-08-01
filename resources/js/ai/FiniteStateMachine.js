export class FiniteStateMachine {
    constructor(states, initialState, context = {}) {
        this.states = states;
        this.context = context;
        this.currentStateName = null;
        this.currentState = null;

        this.transition(initialState);
    }

    transition(nextStateName) {
        if (!this.states[nextStateName] || nextStateName === this.currentStateName) {
            return false;
        }

        this.currentState?.exit?.(this.context);
        this.currentStateName = nextStateName;
        this.currentState = this.states[nextStateName];
        this.currentState.enter?.(this.context);

        return true;
    }

    update(dt) {
        const nextStateName = this.currentState?.update?.(this.context, dt);
        if (nextStateName) {
            this.transition(nextStateName);
        }
    }

    get state() {
        return this.currentStateName;
    }
}
